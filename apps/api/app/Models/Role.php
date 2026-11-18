<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A backoffice role: a name, a rank (who may manage whom) and a set of permissions.
 * The four built-in roles are "system" roles: they cannot be deleted, and the Developer role cannot be changed.
 */
#[Fillable(['key', 'label', 'description', 'rank', 'permissions', 'is_system'])]
class Role extends Model
{
    public const DEVELOPER = 'developer';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
            'rank' => 'integer',
        ];
    }
}
