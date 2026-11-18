<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Database\QueryException;

/**
 * Roles and what each may do. Stored in the "roles" table (edited in Roles & permissions, developer only);
 * config/permissions.php holds the built-in starting set and is used when the table does not exist yet.
 */
class Roles
{
    /** Rank of the Developer role: always above every other role. */
    public const DEVELOPER_RANK = 1000;

    /**
     * Every permission the system knows, grouped for the roles screen.
     *
     * @var array<string, array<string, array{0: string, 1: string}>>
     */
    public const CATALOG = [
        'Overview & reports' => [
            'dashboard.view' => ['See the overview', 'The Overview page with today\'s numbers.'],
            'reports.view' => ['See reports', 'Registration, event and source reports.'],
        ],
        'Events' => [
            'events.view' => ['See events', 'Open events and their settings, read-only.'],
            'events.manage' => ['Manage events', 'Create, edit, publish, duplicate and delete events and their forms.'],
        ],
        'Microsites' => [
            'microsites.view' => ['See microsites', 'Open microsites and the page builder, read-only.'],
            'microsites.manage' => ['Manage microsites', 'Create, edit, publish and delete microsites.'],
        ],
        'Applicants' => [
            'applicants.view' => ['See applicants', 'Lists and details of registrations.'],
            'applicants.manage' => ['Manage applicants', 'Edit answers, change status, resend emails, delete.'],
            'applicants.export' => ['Export applicants', 'Download applicant lists as CSV or JSON.'],
        ],
        'Users' => [
            'users.view' => ['See users', 'The list of backoffice accounts.'],
            'users.manage' => ['Manage users', 'Add, edit, deactivate users of a lower rank; reset passwords and 2FA.'],
        ],
        'System' => [
            'audit.view' => ['See audit logs', 'Everything that happened in the system.'],
            'audit.export' => ['Export audit logs', 'Download audit logs as CSV or JSON.'],
            'settings.manage' => ['Change settings', 'General, security, email, appearance, logs and extra settings.'],
        ],
    ];

    /** @var array<string, array{label: string, description: ?string, rank: int, permissions: list<string>, is_system: bool}>|null */
    private static ?array $cache = null;

    /**
     * @return list<string>
     */
    public static function permissions(): array
    {
        return array_merge(...array_map('array_keys', array_values(self::CATALOG)));
    }

    /**
     * All roles by key, highest rank first.
     *
     * @return array<string, array{label: string, description: ?string, rank: int, permissions: list<string>, is_system: bool}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $roles = [];
        try {
            foreach (Role::query()->get() as $r) {
                $roles[$r->key] = ['label' => $r->label, 'description' => $r->description, 'rank' => (int) $r->rank, 'permissions' => array_values((array) $r->permissions), 'is_system' => (bool) $r->is_system];
            }
        } catch (QueryException) {
            // Table not migrated yet (fresh install, early tests): fall back to the built-in roles.
        }
        if (! $roles) {
            foreach (config('permissions.roles') as $key => $r) {
                $roles[$key] = ['label' => $r['label'], 'description' => null, 'rank' => (int) $r['rank'], 'permissions' => array_values($r['permissions']), 'is_system' => true];
            }
        }
        // The Developer can always do everything, so nobody can lock the system.
        $roles[Role::DEVELOPER] = ['label' => $roles[Role::DEVELOPER]['label'] ?? 'Developer', 'description' => $roles[Role::DEVELOPER]['description'] ?? null, 'rank' => self::DEVELOPER_RANK, 'permissions' => self::permissions(), 'is_system' => true];
        uasort($roles, fn ($a, $b) => $b['rank'] <=> $a['rank']);

        return self::$cache = $roles;
    }

    /**
     * @return array{label: string, description: ?string, rank: int, permissions: list<string>, is_system: bool}|null
     */
    public static function get(string $key): ?array
    {
        return self::all()[$key] ?? null;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }
}
