<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Roles;
use App\Support\Settings;
use App\Support\TwoFactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Login method (username / email / either) so the sign-in form shows the right label. */
    public function config(): JsonResponse
    {
        return response()->json([
            'login_method' => setting('security', 'login_method', 'either'),
            'site_name' => setting('general', 'site_name', 'Event Plus'),
            'logo' => setting('general', 'logo') ?: null,
            'favicon' => setting('general', 'favicon') ?: null,
            'theme' => setting('appearance', 'theme', 'burgundy'),
            'setup_needed' => SetupController::needed(),
        ]);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'login' => ['required', 'string', 'max:190'],
            'password' => ['required', 'string', 'max:255'],
        ]);
        $login = strtolower(trim($data['login']));
        $key = 'login:'.sha1($login.'|'.$request->ip());
        $this->ensureNotLocked($key, $login);

        $method = setting('security', 'login_method', 'either');
        $user = User::query()
            ->where(function ($q) use ($login, $method) {
                if ($method === 'username') {
                    $q->where('username', $login);
                } elseif ($method === 'email') {
                    $q->where('email', $login);
                } else {
                    $q->where('username', $login)->orWhere('email', $login);
                }
            })
            ->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($key, $this->lockSeconds());
            Audit::error('auth.login_failed', "Failed sign-in attempt for \"{$login}\"", "Someone tried to sign in as \"{$login}\" from {$request->ip()} ({$this->device()}), but the ".($user ? 'password was wrong' : 'account does not exist').'. Repeated attempts are blocked for a while.', $user, ['login' => $login], $user);

            throw ValidationException::withMessages(['login' => 'These details do not match our records.']);
        }

        if (! $user->isActive()) {
            Audit::error('auth.login_inactive', "{$user->name} tried to sign in but the account is inactive", "{$user->name} entered the correct password, but their account is switched off (Inactive), so sign-in was refused.", $user, [], $user);

            throw ValidationException::withMessages(['login' => 'This account is inactive. Please contact an administrator.']);
        }

        RateLimiter::clear($key);

        if ($user->hasTwoFactor()) {
            $request->session()->put('login.pending', ['id' => $user->id, 'at' => time()]);

            return response()->json(['two_factor' => true]);
        }

        $this->completeLogin($request, $user, false);

        return response()->json(['ok' => true]);
    }

    public function twoFactor(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:10']]);
        $pending = $request->session()->get('login.pending');
        if (! is_array($pending) || time() - (int) $pending['at'] > 300) {
            $request->session()->forget('login.pending');

            throw ValidationException::withMessages(['code' => 'Your sign-in expired. Please start again.']);
        }

        $user = User::query()->find($pending['id']);
        $key = 'login2fa:'.$pending['id'].'|'.$request->ip();
        $this->ensureNotLocked($key, (string) $user?->username, 'code');

        $device = $user ? TwoFactor::verifyUser($user, $data['code']) : null;
        if (! $user || ! $device) {
            RateLimiter::hit($key, $this->lockSeconds());
            Audit::error('auth.two_factor_failed', "Wrong 2FA code for {$user?->name}", "{$user?->name} entered the right password but a wrong code from their authenticator app.", $user, [], $user);

            throw ValidationException::withMessages(['code' => 'That code is not correct. Check your authenticator app and try again.']);
        }

        RateLimiter::clear($key);
        $request->session()->forget('login.pending');
        $this->completeLogin($request, $user, true, $device->name);

        return response()->json(['ok' => true]);
    }

    public function logout(Request $request): JsonResponse
    {
        $user = $request->user();
        $reason = $request->input('reason') === 'idle' ? 'idle' : 'manual';
        if ($user) {
            $reason === 'idle'
                ? Audit::log('auth.idle_logout', "{$user->name} was signed out after being inactive", "{$user->name} was inactive in the backoffice for the allowed time, so the inactivity pop-up signed them out.", 'success', $user)
                : Audit::log('auth.logout', "{$user->name} signed out", "{$user->name} signed out of the backoffice.", 'success', $user);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    /** Called by the backoffice while the person is active (mouse, keyboard), so the server idle timer matches. */
    public function heartbeat(Request $request): JsonResponse
    {
        $request->session()->put('last_seen', time());

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $appearance = Settings::group('appearance');

        return response()->json([
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'username' => $user->username,
                'email' => $user->email,
                'role' => $user->role,
                'role_label' => $user->roleLabel(),
                'theme' => $user->theme,
                'must_change_password' => $user->must_change_password,
                'has_two_factor' => $user->hasTwoFactor(),
            ],
            'permissions' => $user->permissions(),
            'roles' => collect(Roles::all())->except(Role::DEVELOPER)->map(fn ($r, $k) => ['value' => $k, 'label' => $r['label'], 'rank' => $r['rank']])->values(),
            'is_developer' => $user->role === Role::DEVELOPER,
            'assignable_roles' => $user->assignableRoles(),
            'settings' => [
                'site_name' => setting('general', 'site_name'),
                'logo' => setting('general', 'logo') ?: null,
                'favicon' => setting('general', 'favicon') ?: null,
                'date_format' => setting('general', 'date_format'),
                'timezone' => setting('general', 'timezone'),
                'theme' => $appearance['theme'],
                'menu_position' => $appearance['menu_position'],
                'allow_user_theme' => (bool) $appearance['allow_user_theme'],
                'idle_minutes' => (int) setting('security', 'idle_minutes', 120),
                'require_2fa' => (bool) setting('security', 'require_2fa', false),
                'login_method' => setting('security', 'login_method'),
                'web_url' => rtrim((string) env('WEB_URL', 'http://localhost:4003'), '/'),
            ],
        ]);
    }

    private function completeLogin(Request $request, User $user, bool $usedTwoFactor, ?string $deviceName = null): void
    {
        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put('last_seen', time());
        $user->forceFill(['last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();

        Audit::log(
            'auth.login',
            "{$user->name} signed in",
            "{$user->name} signed in to the backoffice from {$request->ip()} using {$this->device()}".($usedTwoFactor ? ", confirmed with the authenticator app on \"{$deviceName}\"." : '.'),
            'success',
            $user,
            ['two_factor' => $usedTwoFactor],
            $user,
        );
    }

    private function ensureNotLocked(string $key, string $login, string $field = 'login'): void
    {
        $max = max(3, (int) setting('security', 'max_login_attempts', 5));
        if (RateLimiter::tooManyAttempts($key, $max)) {
            $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
            Audit::error('auth.locked', "Sign-in blocked for \"{$login}\" after too many attempts", "There were {$max} or more failed sign-in attempts for \"{$login}\" from ".request()->ip().", so further attempts are blocked for {$minutes} minute(s) to stop password guessing.", null, ['login' => $login]);

            throw ValidationException::withMessages([$field => "Too many attempts. Please try again in {$minutes} minute(s)."]);
        }
    }

    private function lockSeconds(): int
    {
        return max(1, (int) setting('security', 'lockout_minutes', 15)) * 60;
    }

    private function device(): string
    {
        return Audit::device();
    }
}
