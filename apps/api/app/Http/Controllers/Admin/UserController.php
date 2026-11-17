<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TwoFactorDevice;
use App\Models\User;
use App\Support\Audit;
use App\Support\PasswordRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        abort_unless($me->hasPermission('users.view'), 403);

        $users = User::query()
            ->visibleTo($me)
            ->when($request->string('q')->trim()->value(), function ($q, string $term) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")->orWhere('username', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%"));
            })
            ->when($request->input('role'), fn ($q, $role) => $q->where('role', $role))
            ->when($request->input('status'), fn ($q, $status) => $q->where('status', $status))
            ->withCount(['twoFactorDevices as two_factor_count' => fn ($q) => $q->whereNotNull('confirmed_at')])
            ->orderBy('name')
            ->paginate(min(100, max(5, (int) $request->input('per_page', 20))));

        $online = $this->onlineUserIds();

        return response()->json([
            'data' => collect($users->items())->map(fn (User $u) => $this->transform($u, $me, $online))->all(),
            'meta' => ['total' => $users->total(), 'page' => $users->currentPage(), 'per_page' => $users->perPage()],
        ]);
    }

    public function show(Request $request, User $user): JsonResponse
    {
        $me = $this->visibleOr404($request, $user);
        abort_unless($me->id === $user->id || $me->hasPermission('users.view'), 404);
        $user->loadCount(['twoFactorDevices as two_factor_count' => fn ($q) => $q->whereNotNull('confirmed_at')]);

        return response()->json(['data' => $this->transform($user, $me, $this->onlineUserIds())]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $me */
        $me = $request->user();
        abort_unless($me->hasPermission('users.manage'), 403);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-zA-Z0-9._-]+$/', 'unique:users,username'],
            'email' => ['required', 'email:rfc', 'max:190', 'unique:users,email'],
            'role' => ['required', Rule::in($me->assignableRoles())],
            'status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])],
            'password' => ['required', 'confirmed', PasswordRules::make()],
            'must_change_password' => ['boolean'],
        ]);

        $user = User::query()->create([
            ...$data,
            'username' => strtolower($data['username']),
            'email' => strtolower($data['email']),
            'must_change_password' => (bool) ($data['must_change_password'] ?? true),
            'password_changed_at' => now(),
        ]);

        Audit::log('user.created', "{$me->name} created the user {$user->name}", "{$me->name} added a new {$user->roleLabel()} account for {$user->name} ({$user->username}, {$user->email}). The account is ".($user->isActive() ? 'active' : 'inactive').($user->must_change_password ? ' and must choose a new password at first sign-in.' : '.'), 'success', $user);

        return response()->json(['data' => $this->transform($user, $me, [])], 201);
    }

    public function update(Request $request, User $user): JsonResponse
    {
        $me = $this->visibleOr404($request, $user);
        $self = $me->id === $user->id;
        abort_unless($self || $me->canManage($user), 403);

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'username' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[a-zA-Z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user->id)],
            'email' => ['required', 'email:rfc', 'max:190', Rule::unique('users', 'email')->ignore($user->id)],
            'theme' => ['nullable', 'string', 'max:60'],
        ];
        if (! $self) {
            $rules['role'] = ['required', Rule::in($me->assignableRoles())];
            $rules['status'] = ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])];
        }
        $data = $request->validate($rules);
        $data['username'] = strtolower($data['username']);
        $data['email'] = strtolower($data['email']);
        if (! setting('appearance', 'allow_user_theme', false)) {
            unset($data['theme']);
        }

        $user->fill($data);
        $changes = array_keys($user->getDirty());
        $before = array_intersect_key($user->getOriginal(), array_flip($changes));
        $user->save();

        if ($changes) {
            $labels = ['name' => 'name', 'username' => 'username', 'email' => 'email', 'role' => 'role', 'status' => 'status', 'theme' => 'theme'];
            $list = implode(', ', array_map(fn ($c) => $labels[$c] ?? $c, $changes));
            Audit::log('user.updated', ($self ? "{$me->name} updated their own account" : "{$me->name} updated the user {$user->name}"), ($self ? "{$me->name} changed their own {$list}." : "{$me->name} changed the {$list} of {$user->name}."), 'success', $user, ['changed' => $changes, 'before' => $before, 'after' => array_intersect_key($user->only($changes), array_flip($changes))]);
            if (in_array('status', $changes, true) && ! $user->isActive()) {
                $user->endAllSessions();
            }
        }

        return response()->json(['data' => $this->transform($user->loadCount(['twoFactorDevices as two_factor_count' => fn ($q) => $q->whereNotNull('confirmed_at')]), $me, $this->onlineUserIds())]);
    }

    public function status(Request $request, User $user): JsonResponse
    {
        $me = $this->managed($request, $user);
        $data = $request->validate(['status' => ['required', Rule::in([User::STATUS_ACTIVE, User::STATUS_INACTIVE])]]);
        $user->forceFill(['status' => $data['status']])->save();
        if (! $user->isActive()) {
            $user->endAllSessions();
        }

        $user->isActive()
            ? Audit::log('user.activated', "{$me->name} activated {$user->name}", "{$me->name} switched {$user->name}'s account to Active, so they can sign in again.", 'success', $user)
            : Audit::log('user.deactivated', "{$me->name} deactivated {$user->name}", "{$me->name} switched {$user->name}'s account to Inactive. They were signed out and can no longer sign in.", 'success', $user);

        return response()->json(['ok' => true, 'status' => $user->status]);
    }

    /** Quick reset: a temporary password the user must change at next sign-in. Shown once to the admin. */
    public function resetPassword(Request $request, User $user): JsonResponse
    {
        $me = $this->managed($request, $user);
        $temporary = $this->temporaryPassword();
        $user->forceFill(['password' => $temporary, 'must_change_password' => true, 'password_changed_at' => now()])->save();
        $ended = $user->endAllSessions();

        Audit::log('user.password_reset', "{$me->name} reset the password of {$user->name}", "{$me->name} gave {$user->name} a temporary password. {$user->name} was signed out".($ended ? " of {$ended} session(s)" : '').' and must choose a new password at the next sign-in.', 'success', $user);

        return response()->json(['temporary_password' => $temporary]);
    }

    /** Set a new password for another user (admin), from the user's Password tab. */
    public function setPassword(Request $request, User $user): JsonResponse
    {
        $me = $this->managed($request, $user);
        $data = $request->validate([
            'password' => ['required', 'confirmed', PasswordRules::make()],
            'must_change_password' => ['boolean'],
        ]);
        $user->forceFill(['password' => $data['password'], 'must_change_password' => (bool) ($data['must_change_password'] ?? false), 'password_changed_at' => now()])->save();
        $user->endAllSessions();

        Audit::log('user.password_set', "{$me->name} set a new password for {$user->name}", "{$me->name} chose a new password for {$user->name}. {$user->name} was signed out everywhere.", 'success', $user);

        return response()->json(['ok' => true]);
    }

    public function resetTwoFactor(Request $request, User $user): JsonResponse
    {
        $me = $this->managed($request, $user);
        $count = $user->twoFactorDevices()->count();
        $user->twoFactorDevices()->delete();

        Audit::log('user.two_factor_reset', "{$me->name} reset 2FA for {$user->name}", "{$me->name} removed all {$count} authenticator device(s) from {$user->name}'s account. {$user->name} can now sign in with just the password".(setting('security', 'require_2fa') ? ' and will have to set up 2FA again.' : '.'), 'success', $user);

        return response()->json(['ok' => true]);
    }

    public function twoFactorDevices(Request $request, User $user): JsonResponse
    {
        $me = $this->visibleOr404($request, $user);
        abort_unless($me->id === $user->id || $me->hasPermission('users.view'), 404);

        return response()->json(['devices' => AccountController::devices($user), 'max' => User::MAX_TWO_FACTOR_DEVICES]);
    }

    public function removeTwoFactorDevice(Request $request, User $user, TwoFactorDevice $device): JsonResponse
    {
        $me = $this->managed($request, $user);
        abort_unless($device->user_id === $user->id, 404);
        $device->delete();
        Audit::log('user.two_factor_device_removed', "{$me->name} removed {$user->name}'s 2FA device \"{$device->name}\"", "{$me->name} removed the authenticator app on \"{$device->name}\" from {$user->name}'s account.", 'success', $user);

        return response()->json(['devices' => AccountController::devices($user)]);
    }

    public function forceLogout(Request $request, User $user): JsonResponse
    {
        $me = $this->managed($request, $user);
        $ended = $user->endAllSessions();
        Audit::log('user.force_logout', "{$me->name} signed {$user->name} out everywhere", "{$me->name} used Force logout on {$user->name}. {$ended} active session(s) were ended; {$user->name} must sign in again.", 'success', $user, ['sessions' => $ended]);

        return response()->json(['ok' => true, 'sessions' => $ended]);
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $me = $this->managed($request, $user);
        $user->endAllSessions();
        $label = "{$user->name} ({$user->username})";
        $user->delete();
        Audit::log('user.deleted', "{$me->name} deleted the user {$label}", "{$me->name} permanently deleted the account of {$label}. Their past activity stays in the audit log.", 'success', null, [], null, $label);

        return response()->json(['ok' => true]);
    }

    private function visibleOr404(Request $request, User $user): User
    {
        /** @var User $me */
        $me = $request->user();
        abort_if($user->is_hidden && ! $me->is_hidden, 404);

        return $me;
    }

    private function managed(Request $request, User $user): User
    {
        $me = $this->visibleOr404($request, $user);
        abort_unless($me->canManage($user), 403, 'You cannot manage this user.');

        return $me;
    }

    /**
     * @return array<int, bool>
     */
    private function onlineUserIds(): array
    {
        $since = time() - max(5, (int) setting('security', 'idle_minutes', 120)) * 60;

        return DB::table(config('session.table', 'sessions'))
            ->whereNotNull('user_id')->where('last_activity', '>=', $since)
            ->pluck('user_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }

    /**
     * @param  array<int, bool>  $online
     * @return array<string, mixed>
     */
    private function transform(User $user, User $me, array $online): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'username' => $user->username,
            'email' => $user->email,
            'role' => $user->role,
            'role_label' => $user->roleLabel(),
            'status' => $user->status,
            'theme' => $user->theme,
            'must_change_password' => $user->must_change_password,
            'two_factor_count' => (int) ($user->two_factor_count ?? 0),
            'last_login_at' => $user->last_login_at?->toIso8601String(),
            'last_login_ip' => $me->hasPermission('users.manage') ? $user->last_login_ip : null,
            'created_at' => $user->created_at?->toIso8601String(),
            'online' => isset($online[$user->id]),
            'is_self' => $me->id === $user->id,
            'can_manage' => $me->canManage($user),
        ];
    }

    private function temporaryPassword(): string
    {
        return Str::password(14, true, true, true, false);
    }
}
