<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Records every activity in the audit log, successes and errors alike.
 *
 * `summary` is one short line for the list; `description` is the plain-language explanation
 * shown when a row is expanded, written for non-technical readers.
 */
class Audit
{
    /** @var array<string, string> */
    public const CATEGORIES = [
        'auth' => 'Sign-in & security',
        'user' => 'Users',
        'event' => 'Events',
        'applicant' => 'Applicants',
        'registration' => 'Public registrations',
        'microsite' => 'Microsites',
        'settings' => 'Settings',
        'email' => 'Emails',
        'media' => 'Media',
        'export' => 'Exports',
        'system' => 'System',
    ];

    /**
     * @param  array<string, mixed>  $properties  technical details (never passwords or secrets)
     */
    public static function log(
        string $action,
        string $summary,
        ?string $description = null,
        string $status = 'success',
        ?Model $subject = null,
        array $properties = [],
        ?User $actor = null,
        ?string $subjectLabel = null,
    ): void {
        try {
            $actor ??= Auth::user();
            $request = request();
            $category = strtok($action, '.') ?: 'system';
            // Public-site calls carry the visitor's IP and browser in X-Client-* headers (set by that site's server).
            $public = $request?->is('api/public/*');
            $ip = $public && filter_var($request->header('X-Client-Ip'), FILTER_VALIDATE_IP) ? $request->header('X-Client-Ip') : $request?->ip();
            $agent = $public && $request->header('X-Client-Agent') ? $request->header('X-Client-Agent') : $request?->userAgent();

            AuditLog::query()->create([
                'user_id' => $actor?->id,
                'user_name' => $actor?->name,
                'category' => array_key_exists($category, self::CATEGORIES) ? $category : 'system',
                'action' => $action,
                'status' => $status,
                'summary' => mb_substr($summary, 0, 500),
                'description' => $description,
                'subject_type' => $subject ? strtolower(class_basename($subject)) : null,
                'subject_id' => $subject?->getKey(),
                'subject_label' => $subjectLabel ?? self::labelFor($subject),
                'properties' => $properties ?: null,
                'ip_address' => $ip,
                'user_agent' => mb_substr((string) $agent, 0, 500) ?: null,
                'is_hidden' => (bool) $actor?->is_hidden,
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('Audit log failed: '.$e->getMessage());
        }
    }

    public static function error(string $action, string $summary, ?string $description = null, ?Model $subject = null, array $properties = [], ?User $actor = null): void
    {
        self::log($action, $summary, $description, 'error', $subject, $properties, $actor);
    }

    /** "Chrome on Windows" from a user agent, for plain-language descriptions. */
    public static function device(?string $userAgent = null): string
    {
        $ua = $userAgent ?? (string) request()?->userAgent();
        $browser = match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') => 'Opera',
            str_contains($ua, 'Chrome/') => 'Chrome',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'an unknown browser',
        };
        $os = match (true) {
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'iPhone'), str_contains($ua, 'iPad') => 'iOS',
            str_contains($ua, 'Mac OS') => 'macOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'an unknown system',
        };

        return "{$browser} on {$os}";
    }

    private static function labelFor(?Model $subject): ?string
    {
        if (! $subject) {
            return null;
        }
        foreach (['title', 'name', 'username', 'email'] as $attribute) {
            $value = $subject->getAttribute($attribute);
            if (is_string($value) && $value !== '') {
                return mb_substr($value, 0, 255);
            }
        }

        return class_basename($subject).' #'.$subject->getKey();
    }
}
