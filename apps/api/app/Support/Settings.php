<?php

namespace App\Support;

use App\Models\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * System settings, stored per group in the `settings` table, with defaults for every key.
 * Secret keys (the SMTP password) are stored encrypted and never returned to the browser.
 */
class Settings
{
    /** @var array<string, array<string, mixed>>|null */
    private static ?array $cache = null;

    /** @var array<string, list<string>> */
    public const SECRET_KEYS = ['email' => ['password']];

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defaults(): array
    {
        return [
            'general' => [
                'site_name' => 'Event Plus',
                'timezone' => 'Asia/Kuala_Lumpur',
                'date_format' => 'D MMM YYYY',
                'contact_email' => '',
                'logo' => '',
                'favicon' => '',
            ],
            'security' => [
                'login_method' => 'either',
                'idle_minutes' => 120,
                'require_2fa' => false,
                'password_min_length' => 8,
                'password_require_mixed' => true,
                'max_login_attempts' => 5,
                'lockout_minutes' => 15,
            ],
            'email' => [
                'mailer' => 'log',
                'host' => '',
                'port' => 587,
                'encryption' => 'tls',
                'username' => '',
                'password' => '',
                'from_address' => '',
                'from_name' => 'Event Plus',
                'admin_recipients' => '',
            ],
            'appearance' => [
                'theme' => 'burgundy',
                'menu_position' => 'sidebar',
                'allow_user_theme' => false,
            ],
            'logs' => [
                'retention_days' => 365,
            ],
            'extra' => [
                'home_mode' => 'maintenance',
                'maintenance_title' => 'This site is under maintenance',
                'maintenance_message' => 'Please check back later.',
                'option_lists' => [],
                'capacity_alert' => false,
                'capacity_alert_pct' => 90,
                'waitlist_mode' => 'manual',
                'mask_viewers' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function group(string $group): array
    {
        self::load();

        return self::$cache[$group] ?? [];
    }

    public static function get(string $group, string $key, mixed $default = null): mixed
    {
        self::load();

        return self::$cache[$group][$key] ?? $default;
    }

    /**
     * Save some keys of one group. Unknown keys are ignored. Returns the keys that changed.
     *
     * @param  array<string, mixed>  $values
     * @return list<string>
     */
    public static function put(string $group, array $values): array
    {
        $defaults = self::defaults()[$group] ?? [];
        $current = self::group($group);
        $changed = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $defaults)) {
                continue;
            }
            $secret = in_array($key, self::SECRET_KEYS[$group] ?? [], true);
            if ($secret && ($value === null || $value === '')) {
                continue; // blank secret = keep the stored one
            }
            if (! $secret && ($current[$key] ?? null) === $value) {
                continue;
            }
            $stored = $secret ? Crypt::encryptString((string) $value) : $value;
            Setting::query()->updateOrCreate(['group' => $group, 'key' => $key], ['value' => $stored]);
            $changed[] = $key;
        }

        self::$cache = null;

        return $changed;
    }

    /**
     * A group for the browser: secret keys replaced by "<key>_set" flags.
     *
     * @return array<string, mixed>
     */
    public static function forClient(string $group): array
    {
        $values = self::group($group);
        foreach (self::SECRET_KEYS[$group] ?? [] as $key) {
            $values[$key.'_set'] = ($values[$key] ?? '') !== '';
            $values[$key] = '';
        }

        return $values;
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    private static function load(): void
    {
        if (self::$cache !== null) {
            return;
        }
        $values = self::defaults();
        try {
            if (Schema::hasTable('settings')) {
                foreach (Setting::query()->get() as $row) {
                    if (! isset($values[$row->group]) || ! array_key_exists($row->key, $values[$row->group])) {
                        continue;
                    }
                    $value = $row->value;
                    if (in_array($row->key, self::SECRET_KEYS[$row->group] ?? [], true) && is_string($value) && $value !== '') {
                        try {
                            $value = Crypt::decryptString($value);
                        } catch (Throwable) {
                            $value = '';
                        }
                    }
                    $values[$row->group][$row->key] = $value;
                }
            }
        } catch (Throwable) {
            // Database not ready (fresh install): defaults only.
        }
        self::$cache = $values;
    }
}
