<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\Audit;
use App\Support\MailSettings;
use App\Support\Settings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class SettingsController extends Controller
{
    /** @var array<string, string> */
    private const LABELS = [
        'general' => 'General',
        'security' => 'Security',
        'email' => 'Email / SMTP',
        'appearance' => 'Appearance',
        'logs' => 'Logs',
        'extra' => 'Extra',
    ];

    public function show(string $group): JsonResponse
    {
        abort_unless(isset(self::LABELS[$group]), 404);

        return response()->json(['data' => Settings::forClient($group)]);
    }

    public function update(Request $request, string $group): JsonResponse
    {
        abort_unless(isset(self::LABELS[$group]), 404);
        $data = $request->validate($this->rules($group));

        if ($group === 'extra' && isset($data['option_lists'])) {
            $data['option_lists'] = collect($data['option_lists'])->map(fn ($l) => [
                'name' => trim($l['name']),
                'slug' => Str::slug(($l['slug'] ?? '') ?: $l['name']),
                'options' => array_values(array_filter(array_map('trim', $l['options'] ?? []), 'strlen')),
            ])->values()->all();
        }

        $changed = Settings::put($group, $data);
        if ($changed) {
            $label = self::LABELS[$group];
            $safe = array_diff($changed, Settings::SECRET_KEYS[$group] ?? []);
            Audit::log(
                'settings.updated',
                "{$request->user()->name} changed {$label} settings",
                "{$request->user()->name} saved Settings → {$label}. Changed: ".implode(', ', array_map(fn ($k) => str_replace('_', ' ', $k), $changed)).'.',
                'success',
                null,
                ['group' => $group, 'changed' => $changed, 'values' => array_intersect_key(Settings::group($group), array_flip($safe))],
                null,
                $label.' settings',
            );
        }

        return response()->json(['data' => Settings::forClient($group), 'changed' => $changed]);
    }

    public function testEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['to' => ['required', 'email']]);
        MailSettings::apply();
        try {
            Mail::html('<p>This is a test email from '.e(setting('general', 'site_name')).'. Your email settings work.</p>', function ($m) use ($data) {
                $m->to($data['to'])->subject('Test email from '.setting('general', 'site_name'));
            });
        } catch (Throwable $e) {
            Audit::error('email.test_failed', "Test email to {$data['to']} failed", "{$request->user()->name} sent a test email to {$data['to']}, but the mail server refused it. The server said: \"{$e->getMessage()}\". Check the host, port, encryption, username and password in Settings → Email / SMTP.", null, ['error' => $e->getMessage()]);

            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        $mailer = config('mail.default');
        Audit::log('email.test_sent', "Test email sent to {$data['to']}", "{$request->user()->name} sent a test email to {$data['to']} using ".($mailer === 'smtp' ? 'the SMTP server '.setting('email', 'host') : 'the log file (no SMTP server set, so emails are only written to the server log)').'.', 'success', null, ['mailer' => $mailer]);

        return response()->json(['ok' => true, 'mailer' => $mailer]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(string $group): array
    {
        return match ($group) {
            'general' => [
                'site_name' => ['sometimes', 'required', 'string', 'max:120'],
                'timezone' => ['sometimes', 'required', 'timezone'],
                'date_format' => ['sometimes', 'required', Rule::in(['D MMM YYYY', 'DD/MM/YYYY', 'YYYY-MM-DD', 'MMM D, YYYY'])],
                'contact_email' => ['sometimes', 'nullable', 'email'],
                'logo' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^media\/[A-Za-z0-9._\/-]+$/'],
                'favicon' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^media\/[A-Za-z0-9._\/-]+$/'],
            ],
            'security' => [
                'login_method' => ['sometimes', 'required', Rule::in(['username', 'email', 'either'])],
                'idle_minutes' => ['sometimes', 'required', 'integer', 'min:5', 'max:1440'],
                'require_2fa' => ['sometimes', 'boolean'],
                'password_min_length' => ['sometimes', 'required', 'integer', 'min:8', 'max:64'],
                'password_require_mixed' => ['sometimes', 'boolean'],
                'max_login_attempts' => ['sometimes', 'required', 'integer', 'min:3', 'max:20'],
                'lockout_minutes' => ['sometimes', 'required', 'integer', 'min:1', 'max:1440'],
            ],
            'email' => [
                'mailer' => ['sometimes', 'required', Rule::in(['smtp', 'log'])],
                'host' => ['sometimes', 'nullable', 'string', 'max:190'],
                'port' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:65535'],
                'encryption' => ['sometimes', 'required', Rule::in(['tls', 'ssl', 'none'])],
                'username' => ['sometimes', 'nullable', 'string', 'max:190'],
                'password' => ['sometimes', 'nullable', 'string', 'max:255'],
                'from_address' => ['sometimes', 'nullable', 'email'],
                'from_name' => ['sometimes', 'nullable', 'string', 'max:120'],
                'admin_recipients' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ],
            'appearance' => [
                'theme' => ['sometimes', 'required', 'string', 'max:60'],
                'menu_position' => ['sometimes', 'required', Rule::in(['sidebar', 'top'])],
                'allow_user_theme' => ['sometimes', 'boolean'],
            ],
            'logs' => [
                'retention_days' => ['sometimes', 'required', 'integer', 'min:30', 'max:3650'],
            ],
            'extra' => [
                'home_mode' => ['sometimes', 'required', Rule::in(['maintenance', 'notfound'])],
                'maintenance_title' => ['sometimes', 'nullable', 'string', 'max:190'],
                'maintenance_message' => ['sometimes', 'nullable', 'string', 'max:2000'],
                'option_lists' => ['sometimes', 'array', 'max:50'],
                'option_lists.*.name' => ['required', 'string', 'max:80'],
                'option_lists.*.slug' => ['nullable', 'string', 'max:80'],
                'option_lists.*.options' => ['array', 'max:500'],
                'option_lists.*.options.*' => ['nullable', 'string', 'max:190'],
                'capacity_alert' => ['sometimes', 'boolean'],
                'capacity_alert_pct' => ['sometimes', 'required', 'integer', 'min:10', 'max:100'],
                'waitlist_mode' => ['sometimes', 'required', Rule::in(['manual', 'notify', 'auto'])],
                'mask_viewers' => ['sometimes', 'boolean'],
            ],
            default => [],
        };
    }
}
