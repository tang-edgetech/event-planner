<?php

namespace App\Support;

use App\Models\Applicant;
use App\Models\Event;

/**
 * Replaces {event_title}, {fullname}, {registration_details}… in messages and emails.
 * Values from applicants are HTML-escaped.
 */
class Placeholders
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $fields
     */
    public static function replace(string $text, Event $event, array $data = [], array $fields = []): string
    {
        $map = [
            '{event_title}' => e($event->title),
            '{event_date}' => e((string) ($event->date_text ?: $event->starts_at?->format('j F Y'))),
            '{event_time}' => e((string) ($event->time_text ?: $event->starts_at?->format('g:i A'))),
            '{event_venue}' => e((string) $event->venue),
            '{event_url}' => e(rtrim((string) env('WEB_URL', ''), '/').'/event/'.$event->slug),
            '{site_name}' => e((string) setting('general', 'site_name', 'Event Plus')),
            '{registration_details}' => self::detailsTable($data, $fields),
        ];
        foreach ($data as $key => $value) {
            $map['{'.$key.'}'] = e(is_array($value) ? implode(', ', $value) : (string) $value);
        }
        $map['{fullname}'] ??= e((string) ($data['full_name'] ?? $data['name'] ?? ''));
        $map['{salutation}'] ??= '';

        return strtr($text, $map);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $fields
     */
    public static function detailsTable(array $data, array $fields): string
    {
        $rows = '';
        foreach ($fields as $f) {
            if (in_array($f['type'], ['hidden', 'heading', 'html'], true) || ! array_key_exists($f['name'], $data)) {
                continue;
            }
            $value = $data[$f['name']];
            $value = is_array($value) ? implode(', ', $value) : (string) $value;
            if ($value === '') {
                continue;
            }
            $rows .= '<tr><th align="left" style="padding:6px 12px 6px 0;color:#5e4c53;font-weight:600;vertical-align:top">'.e(strip_tags((string) $f['label'])).'</th><td style="padding:6px 0">'.e($value).'</td></tr>';
        }

        return $rows ? '<table cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:12px 0">'.$rows.'</table>' : '';
    }

    public static function forApplicant(string $text, Applicant $applicant): string
    {
        return self::replace($text, $applicant->event, $applicant->data ?? [], FormSchema::forEvent($applicant->event));
    }
}
