<?php

use App\Support\Settings;

if (! function_exists('setting')) {
    /**
     * Read one system setting (Settings → General, Security, Email, Appearance, Logs, Extra).
     */
    function setting(string $group, string $key, mixed $default = null): mixed
    {
        return Settings::get($group, $key, $default);
    }
}
