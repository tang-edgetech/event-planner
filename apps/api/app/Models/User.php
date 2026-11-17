<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'username', 'email', 'password', 'role', 'status', 'is_hidden', 'must_change_password', 'theme', 'password_changed_at', 'last_login_at', 'last_login_ip'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const MAX_TWO_FACTOR_DEVICES = 2;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_hidden' => 'boolean',
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<TwoFactorDevice, $this>
     */
    public function twoFactorDevices(): HasMany
    {
        return $this->hasMany(TwoFactorDevice::class);
    }

    /**
     * Users the given viewer may see: the hidden Developer account is visible only to hidden users.
     *
     * @param  Builder<User>  $query
     */
    public function scopeVisibleTo(Builder $query, User $viewer): void
    {
        if (! $viewer->is_hidden) {
            $query->where('is_hidden', false);
        }
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function rank(): int
    {
        return (int) config("permissions.roles.{$this->role}.rank", 0);
    }

    public function roleLabel(): string
    {
        return (string) config("permissions.roles.{$this->role}.label", ucfirst($this->role));
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_values(config("permissions.roles.{$this->role}.permissions", []));
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    /**
     * Whether this user may edit, disable or reset the other user. Changes flow downward by rank.
     */
    public function canManage(User $other): bool
    {
        if ($this->id === $other->id) {
            return false;
        }
        if (! $this->hasPermission('users.manage')) {
            return false;
        }
        if ($this->role === 'developer') {
            return true;
        }

        return ! $other->is_hidden && $other->rank() < $this->rank();
    }

    /**
     * Roles this user may give to others.
     *
     * @return list<string>
     */
    public function assignableRoles(): array
    {
        $roles = [];
        foreach (config('permissions.roles') as $key => $role) {
            if ($key === 'developer') {
                continue;
            }
            if ($this->role === 'developer' || $role['rank'] < $this->rank()) {
                $roles[] = $key;
            }
        }

        return $roles;
    }

    public function hasTwoFactor(): bool
    {
        return $this->twoFactorDevices()->whereNotNull('confirmed_at')->exists();
    }

    /**
     * Sign the user out everywhere by deleting their stored sessions.
     */
    public function endAllSessions(?string $exceptSessionId = null): int
    {
        return DB::table(config('session.table', 'sessions'))
            ->where('user_id', $this->id)
            ->when($exceptSessionId, fn ($q) => $q->where('id', '!=', $exceptSessionId))
            ->delete();
    }
}
