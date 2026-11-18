<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\User;
use App\Support\Audit;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Roles & permissions. Only the Developer can see or change them; for everyone else these addresses do not exist.
 */
class RoleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->developerOnly($request);
        $counts = User::query()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');

        return response()->json([
            'data' => collect(Roles::all())->map(fn ($r, $key) => [
                'key' => $key,
                'label' => $r['label'],
                'description' => $r['description'],
                'rank' => $r['rank'],
                'permissions' => $r['permissions'],
                'is_system' => $r['is_system'],
                'users_count' => (int) ($counts[$key] ?? 0),
                'editable' => $key !== Role::DEVELOPER,
                'deletable' => ! $r['is_system'] && ! ($counts[$key] ?? 0),
            ])->values(),
            'catalog' => collect(Roles::CATALOG)->map(fn ($items, $group) => [
                'group' => $group,
                'items' => collect($items)->map(fn ($p, $key) => ['key' => $key, 'label' => $p[0], 'description' => $p[1]])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $me = $this->developerOnly($request);
        $data = $request->validate($this->rules());
        $key = Str::of($data['key'] ?? '' ?: $data['label'])->slug('_')->limit(40, '')->value() ?: 'role';
        if ($key === Role::DEVELOPER || Role::query()->where('key', $key)->exists()) {
            return response()->json(['message' => 'A role with this name already exists.', 'errors' => ['label' => ['A role with this name already exists. Choose another name.']]], 422);
        }
        $role = Role::query()->create([...$data, 'key' => $key, 'permissions' => $this->clean($data['permissions'] ?? []), 'is_system' => false]);
        Roles::flush();
        Audit::log('role.created', "{$me->name} created the role {$role->label}", "{$me->name} added a new role \"{$role->label}\" (rank {$role->rank}) that can: ".$this->describe($role->permissions).'.', 'success', $role, [], null, $role->label);

        return response()->json(['ok' => true, 'key' => $role->key], 201);
    }

    public function update(Request $request, string $key): JsonResponse
    {
        $me = $this->developerOnly($request);
        abort_if($key === Role::DEVELOPER, 403, 'The Developer role always has every permission and cannot be changed.');
        $role = Role::query()->where('key', $key)->firstOrFail();
        $data = $request->validate($this->rules());
        unset($data['key']);
        $before = $role->permissions;
        $role->fill([...$data, 'permissions' => $this->clean($data['permissions'] ?? [])]);
        $changes = array_keys($role->getDirty());
        $role->save();
        Roles::flush();

        if ($changes) {
            $added = array_values(array_diff($role->permissions, (array) $before));
            $removed = array_values(array_diff((array) $before, $role->permissions));
            $parts = [];
            if ($added) {
                $parts[] = 'can now: '.$this->describe($added);
            }
            if ($removed) {
                $parts[] = 'can no longer: '.$this->describe($removed);
            }
            if (array_diff($changes, ['permissions'])) {
                $parts[] = 'changed its '.implode(', ', array_diff($changes, ['permissions', 'updated_at']));
            }
            Audit::log('role.updated', "{$me->name} changed the role {$role->label}", "{$me->name} edited the role \"{$role->label}\": ".implode('; ', $parts).'. This applies at once to everyone with this role.', 'success', $role, ['added' => $added, 'removed' => $removed], null, $role->label);
        }

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request, string $key): JsonResponse
    {
        $me = $this->developerOnly($request);
        $role = Role::query()->where('key', $key)->firstOrFail();
        abort_if($role->is_system, 403, 'Built-in roles cannot be deleted.');
        $users = User::query()->where('role', $key)->count();
        if ($users) {
            return response()->json(['message' => "{$users} user(s) still have this role. Give them another role first."], 422);
        }
        $role->delete();
        Roles::flush();
        Audit::log('role.deleted', "{$me->name} deleted the role {$role->label}", "{$me->name} deleted the role \"{$role->label}\". No users had it.", 'success', null, [], null, $role->label);

        return response()->json(['ok' => true]);
    }

    private function developerOnly(Request $request): User
    {
        /** @var User $me */
        $me = $request->user();
        abort_unless($me->role === Role::DEVELOPER, 404);

        return $me;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:60'],
            'key' => ['sometimes', 'nullable', 'string', 'max:40', 'regex:/^[a-z][a-z0-9_]*$/'],
            'description' => ['nullable', 'string', 'max:255'],
            'rank' => ['required', 'integer', 'min:1', 'max:'.(Roles::DEVELOPER_RANK - 1)],
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', Rule::in(Roles::permissions())],
        ];
    }

    /**
     * Known permissions only, in catalogue order; "manage" and "export" bring their "view" along.
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    private function clean(array $permissions): array
    {
        foreach ($permissions as $p) {
            if (preg_match('/^(\w+)\.(manage|export)$/', $p, $m)) {
                $permissions[] = $m[1].'.view';
            }
        }

        return array_values(array_intersect(Roles::permissions(), $permissions));
    }

    /**
     * @param  array<int, string>  $permissions
     */
    private function describe(array $permissions): string
    {
        $labels = [];
        foreach (Roles::CATALOG as $items) {
            foreach ($items as $key => [$label]) {
                if (in_array($key, $permissions, true)) {
                    $labels[] = strtolower($label);
                }
            }
        }

        return $labels ? implode(', ', $labels) : 'nothing';
    }
}
