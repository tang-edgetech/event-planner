<?php

namespace App\Support;

use Illuminate\Support\Facades\Config;

/**
 * Applies Settings → Email / SMTP to Laravel's mail configuration at runtime.
 */
class MailSettings
{
    public static function apply(): void
    {
        $s = Settings::group('email');
        $mailer = ($s['mailer'] ?? 'log') === 'smtp' && ($s['host'] ?? '') !== '' ? 'smtp' : 'log';

        Config::set('mail.default', $mailer);
        if ($mailer === 'smtp') {
            $encryption = $s['encryption'] ?? 'tls';
            Config::set('mail.mailers.smtp.host', $s['host']);
            Config::set('mail.mailers.smtp.port', (int) ($s['port'] ?? 587));
            Config::set('mail.mailers.smtp.username', $s['username'] ?: null);
            Config::set('mail.mailers.smtp.password', $s['password'] ?: null);
            Config::set('mail.mailers.smtp.scheme', $encryption === 'ssl' ? 'smtps' : 'smtp');
        }
        if (($s['from_address'] ?? '') !== '') {
            Config::set('mail.from.address', $s['from_address']);
        }
        Config::set('mail.from.name', ($s['from_name'] ?? '') ?: setting('general', 'site_name', 'Event Plus'));

        app()->forgetInstance('mail.manager');
        app()->forgetInstance('mailer');
    }
}
