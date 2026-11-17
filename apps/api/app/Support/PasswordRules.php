<?php

namespace App\Support;

use Illuminate\Validation\Rules\Password;

/**
 * Password rule from Settings → Security (minimum length, mixed characters).
 */
class PasswordRules
{
    public static function make(): Password
    {
        $rule = Password::min(max(8, (int) setting('security', 'password_min_length', 8)));
        if (setting('security', 'password_require_mixed', true)) {
            $rule = $rule->mixedCase()->numbers()->symbols();
        }

        return $rule;
    }
}
