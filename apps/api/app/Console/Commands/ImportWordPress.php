<?php

namespace App\Console\Commands;

use App\Http\Controllers\Admin\MicrositeController;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Media;
use App\Models\Microsite;
use App\Support\Audit;
use App\Support\FormSchema;
use App\Support\Html;
use App\Support\Settings;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Step 2 of the WordPress import: loads the JSON made by database/import/wordpress-export.php.
 * Safe to run again: items already imported (by WordPress ID) are updated, not duplicated.
 */
#[Signature('import:wordpress {file : JSON file made by database/import/wordpress-export.php} {--dry-run : Show what would be imported without saving}')]
#[Description('Import events, registration forms, applicants, microsites, images and settings from the legacy WordPress site')]
class ImportWordPress extends Command
{
    /** @var array<int, string> WordPress attachment ID → new media path ("media/…") */
    private array $media = [];

    /** @var array<int, int> WordPress event ID → new event ID */
    private array $events = [];

    /** @var list<string> */
    private array $notes = [];

    private string $legacyUrl = '';

    public function handle(): int
    {
        $file = (string) $this->argument('file');
        $json = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (! is_array($json) || ! isset($json['events'])) {
            $this->error("Not a WordPress export file: {$file}");

            return self::FAILURE;
        }
        $this->legacyUrl = rtrim((string) ($json['source'] ?? ''), '/');
        $dry = (bool) $this->option('dry-run');

        $this->info(sprintf('Export from %s: %d images, %d events, %d microsites, %d applicants.', $this->legacyUrl ?: 'WordPress', count($json['media'] ?? []), count($json['events']), count($json['microsites'] ?? []), count($json['applicants'] ?? [])));
        if ($dry) {
            $this->warn('Dry run: nothing is saved.');

            return self::SUCCESS;
        }

        try {
            DB::transaction(function () use ($json) {
                $this->importMedia($json['media'] ?? []);
                $this->importSettings($json['settings'] ?? []);
                $this->importEvents($json['events']);
                $this->importMicrosites($json['microsites'] ?? []);
                $this->importApplicants($json['applicants'] ?? []);
            });
        } catch (Throwable $e) {
            $this->error('Import failed, nothing was saved: '.$e->getMessage());
            Audit::error('system.import_failed', 'The WordPress import failed', 'Importing data from the old WordPress site stopped with an error, so nothing was saved: "'.$e->getMessage().'".', null, ['file' => basename($file)]);

            return self::FAILURE;
        }

        foreach ($this->notes as $note) {
            $this->line("  · {$note}");
        }
        $summary = sprintf('%d images, %d events, %d microsites and %d applicants', count($this->media), count($this->events), count($json['microsites'] ?? []), count($json['applicants'] ?? []));
        Audit::log('system.import', "Imported {$summary} from WordPress", "Data from the old WordPress site ({$this->legacyUrl}) was copied into this system: {$summary}. Items imported before were updated rather than duplicated.".($this->notes ? ' Notes: '.implode(' ', $this->notes) : ''), 'success', null, ['notes' => $this->notes]);
        $this->info("Done: {$summary}.");

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $files
     */
    private function importMedia(array $files): void
    {
        foreach ($files as $f) {
            $existing = Media::query()->where('legacy_wp_id', $f['wp_id'])->first();
            if ($existing && Storage::disk('public')->exists('media/'.$existing->path)) {
                $this->media[(int) $f['wp_id']] = 'media/'.$existing->path;

                continue;
            }
            if (! is_file($f['path'])) {
                $this->notes[] = "Image {$f['name']} was missing on disk and was skipped.";

                continue;
            }
            $path = 'wp/'.$f['wp_id'].'-'.Str::slug(pathinfo($f['name'], PATHINFO_FILENAME)).'.'.strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            Storage::disk('public')->put('media/'.$path, (string) file_get_contents($f['path']));
            Media::query()->updateOrCreate(['legacy_wp_id' => $f['wp_id']], [
                'path' => $path,
                'original_name' => $f['name'],
                'mime' => $f['mime'] ?: 'image/jpeg',
                'size' => filesize($f['path']) ?: 0,
                'width' => $f['width'],
                'height' => $f['height'],
                'alt' => $f['alt'] ?: null,
            ]);
            $this->media[(int) $f['wp_id']] = 'media/'.$path;
        }
    }

    /**
     * @param  array<string, mixed>  $s
     */
    private function importSettings(array $s): void
    {
        if (! empty($s['option_lists'])) {
            $current = collect((array) setting('extra', 'option_lists', []))->keyBy('slug');
            foreach ($s['option_lists'] as $list) {
                $current[$list['slug']] = $list;
            }
            Settings::put('extra', ['option_lists' => $current->values()->all()]);
        }
        Settings::put('extra', array_filter([
            'maintenance_title' => $s['maintenance_title'] ?? '',
            'maintenance_message' => $s['maintenance_message'] ?? '',
            'capacity_alert' => $s['capacity_alert'] ?? null,
            'capacity_alert_pct' => $s['capacity_alert_pct'] ?? null,
            'waitlist_mode' => in_array($s['waitlist_mode'] ?? '', ['manual', 'notify', 'auto'], true) ? $s['waitlist_mode'] : null,
            'mask_viewers' => $s['mask_viewers'] ?? null,
        ], fn ($v) => $v !== null && $v !== ''));
        Settings::put('email', array_filter([
            'admin_recipients' => $s['admin_recipients'] ?? '',
            'from_name' => $s['from_name'] ?? '',
            'from_address' => filter_var($s['from_address'] ?? '', FILTER_VALIDATE_EMAIL) ? $s['from_address'] : '',
        ], fn ($v) => $v !== ''));
        if (filter_var($s['contact_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            Settings::put('general', ['contact_email' => $s['contact_email']]);
        }
    }

    /**
     * @param  list<array<string, mixed>>  $events
     */
    private function importEvents(array $events): void
    {
        foreach ($events as $e) {
            // Missing keys (older exports, unset ACF fields) count as empty.
            $f = ((array) $e['fields']) + array_fill_keys(['venue', 'event_date_text', 'event_time_text', 'venue_map', 'banner_desktop', 'banner_mobile', 'accent_color', 'page_bg', 'show_title', 'page_layout', 'summary', 'form_intro', 'submit_label', 'reg_status', 'capacity_min', 'capacity_max', 'enable_waitlist', 'default_status', 'full_title', 'closed_title', 'full_message', 'closed_message', 'waitlist_message', 'waitlist_confirmation', 'confirmation_type', 'confirmation_message', 'redirect_url', 'notify_admin', 'admin_emails', 'notify_applicant', 'email_subject', 'email_body'], null);
            $html = fn ($v) => Html::clean($this->fixUrls(is_string($v) ? $v : null));
            $event = Event::withTrashed()->where('legacy_wp_id', $e['wp_id'])->first() ?? new Event(['legacy_wp_id' => $e['wp_id']]);
            $event->fill([
                'title' => $e['title'],
                'slug' => $this->uniqueSlug(Event::class, $e['slug'] ?: Str::slug($e['title']), $event->id),
                'status' => $e['status'],
                'starts_at' => $e['fields']['event_start'] ?? null,
                'ends_at' => $e['fields']['event_end'] ?? null,
                'reg_open_at' => $e['fields']['reg_open_at'] ?? null,
                'reg_close_at' => $e['fields']['reg_close_at'] ?? null,
                'date_text' => $f['event_date_text'] ?: null,
                'time_text' => $f['event_time_text'] ?: null,
                'venue' => $f['venue'] ?: null,
                'venue_map_url' => $f['venue_map'] ?: null,
                'summary' => $html($f['summary']),
                'banner_desktop' => $this->mediaRef($f['banner_desktop']),
                'banner_mobile' => $this->mediaRef($f['banner_mobile']),
                'accent_color' => $this->color($f['accent_color']),
                'page_bg' => $this->color($f['page_bg']),
                'page_layout' => $f['page_layout'] === 'single' ? 'single' : 'split',
                'show_title' => $f['show_title'] !== false,
                'form_intro' => $html($f['form_intro']),
                'form_fields' => $this->formFields($e['form_fields'] ?? []),
                'submit_label' => $f['submit_label'] ?: 'Submit',
                'reg_status' => in_array($f['reg_status'], ['auto', 'open', 'full', 'closed'], true) ? $f['reg_status'] : 'auto',
                'capacity_min' => $f['capacity_min'] !== '' && $f['capacity_min'] !== null ? (int) $f['capacity_min'] : null,
                'capacity_max' => $f['capacity_max'] !== '' && $f['capacity_max'] !== null ? (int) $f['capacity_max'] : null,
                'enable_waitlist' => (bool) $f['enable_waitlist'],
                'default_status' => $f['default_status'] === 'confirmed' ? 'confirmed' : 'registered',
                'full_title' => $f['full_title'] ?: null,
                'closed_title' => $f['closed_title'] ?: null,
                'full_message' => $html($f['full_message']),
                'closed_message' => $html($f['closed_message']),
                'waitlist_message' => $f['waitlist_message'] ?: null,
                'waitlist_confirmation' => $html($f['waitlist_confirmation']),
                'confirmation_type' => $f['confirmation_type'] === 'redirect' ? 'redirect' : 'message',
                'confirmation_message' => $html($f['confirmation_message']),
                'redirect_url' => $f['redirect_url'] ?: null,
                'notify_admin' => (bool) $f['notify_admin'],
                'admin_emails' => $f['admin_emails'] ?: null,
                'notify_applicant' => (bool) $f['notify_applicant'],
                'email_subject' => $f['email_subject'] ?: null,
                'email_body' => $html($f['email_body']),
            ]);
            if (! $event->exists) {
                $event->created_at = Carbon::parse($e['created_at']);
            }
            $event->save();
            $this->events[(int) $e['wp_id']] = $event->id;
        }
    }

    /**
     * Theme-normalised WordPress fields → builder rows. Keys keep the WordPress name (dashes become underscores).
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function formFields(array $fields): array
    {
        $rows = array_map(function ($f) {
            $cond = is_array($f['conditions'] ?? null) && ! empty($f['conditions']['field']) ? $f['conditions'] : null;

            return [
                'type' => $f['type'],
                'label' => $f['label'],
                'name' => $this->key((string) $f['name']),
                'placeholder' => $f['placeholder'] ?? '',
                'help' => $f['help'] ?? '',
                'required' => (bool) ($f['required'] ?? false),
                'width' => (string) ($f['width'] ?? '100'),
                'options' => array_values((array) ($f['options'] ?? [])),
                'options_source' => 'custom',
                'allow_other' => (bool) ($f['allow_other'] ?? false),
                'phone_format' => $f['type'] === 'phone' ? ($f['format'] ?: 'my') : null,
                'nric_format' => $f['type'] === 'nric' ? ($f['format'] ?: 'my') : null,
                'unique' => (bool) ($f['unique'] ?? false),
                'default' => (string) ($f['default'] ?? ''),
                'content' => Html::clean((string) ($f['content'] ?? '')),
                'conditional' => (bool) $cond,
                'cond_field' => $cond ? $this->key((string) $cond['field']) : null,
                'cond_operator' => $cond['operator'] ?? null,
                'cond_value' => $cond['value'] ?? null,
            ];
        }, $fields);

        return FormSchema::normalise($rows);
    }

    /**
     * @param  list<array<string, mixed>>  $sites
     */
    private function importMicrosites(array $sites): void
    {
        foreach ($sites as $m) {
            $sections = [];
            foreach ((array) $m['sections'] as $row) {
                $layout = $row['acf_fc_layout'] ?? '';
                if ($layout === 'posts') {
                    $this->notes[] = "Microsite \"{$m['title']}\": a News / blog section was left out (the new system has no articles).";

                    continue;
                }
                if (! in_array($layout, MicrositeController::LAYOUTS, true)) {
                    $this->notes[] = "Microsite \"{$m['title']}\": unknown section type \"{$layout}\" skipped.";

                    continue;
                }
                $settings = (array) ($row['settings'] ?? []);
                unset($row['acf_fc_layout'], $row['settings']);
                $sections[] = [
                    'id' => (string) Str::uuid(),
                    'layout' => $layout,
                    'data' => $this->sectionData($layout, $row),
                    'settings' => $this->convert($settings),
                ];
            }

            $s = $m['settings'];
            $cta = is_array($s['nav_cta'] ?? null) ? $s['nav_cta'] : [];
            $site = Microsite::withTrashed()->where('legacy_wp_id', $m['wp_id'])->first() ?? new Microsite(['legacy_wp_id' => $m['wp_id']]);
            $site->fill([
                'title' => $m['title'],
                'slug' => $this->uniqueSlug(Microsite::class, $m['slug'] ?: Str::slug($m['title']), $site->id),
                'status' => $m['status'],
                'event_id' => $this->eventRef($m['linked_event']),
                'sections' => $sections,
                'settings' => [
                    'accent_color' => $this->color($s['accent_color'] ?? null),
                    'page_bg' => $this->color($s['page_bg'] ?? null),
                    'show_nav' => ($s['show_nav'] ?? true) !== false,
                    'nav_logo' => $this->mediaRef($s['nav_logo'] ?? null),
                    'nav_cta_label' => $cta['title'] ?? null,
                    'nav_cta_url' => isset($cta['url']) ? $this->fixUrls($cta['url']) : null,
                    'footer_text' => ($s['footer_text'] ?? '') ?: '© Copyright Protected. All Rights Reserved.',
                    'animations' => ($s['animations'] ?? true) !== false,
                    'custom_css' => (string) ($s['custom_css'] ?? ''),
                ],
            ]);
            if (! $site->exists) {
                $site->created_at = Carbon::parse($m['created_at']);
            }
            $site->save();
        }
    }

    /**
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function sectionData(string $layout, array $row): array
    {
        if (isset($row['buttons'])) {
            $row['buttons'] = array_map(fn ($b) => $this->button((array) $b), (array) (($row['buttons'] ?? []) ?: []));
        }
        if ($layout === 'form') {
            $row['event_id'] = $this->eventRef($row['event'] ?? null);
            unset($row['event']);
        }
        if ($layout === 'cards') {
            $row['items'] = array_map(function ($c) {
                $c = (array) $c;
                $link = is_array($c['link'] ?? null) ? $c['link'] : [];
                $c['link_label'] = $link['title'] ?? '';
                $c['link_url'] = isset($link['url']) ? $this->fixUrls($link['url']) : '';
                unset($c['link']);

                return $c;
            }, (array) (($row['items'] ?? []) ?: []));
        }
        if ($layout === 'video' && is_string($row['embed'] ?? null) && preg_match('/src="([^"]+)"/', $row['embed'], $m)) {
            $row['embed'] = html_entity_decode($m[1]);
        }
        if ($layout === 'banner' && ! empty($row['countdown_to'])) {
            try {
                $row['countdown_to'] = Carbon::parse($row['countdown_to'])->toIso8601String();
            } catch (Throwable) {
                $row['countdown_to'] = null;
            }
        }
        foreach (['items', 'images'] as $list) {
            if (array_key_exists($list, $row) && ! is_array($row[$list])) {
                $row[$list] = [];
            }
        }

        return $this->convert($row);
    }

    /**
     * @param  array<string, mixed>  $b
     * @return array<string, mixed>
     */
    private function button(array $b): array
    {
        $b['event_id'] = ($b['action'] ?? '') === 'event' ? $this->eventRef($b['event'] ?? null) : null;
        unset($b['event']);
        $b['modal_links'] = is_array($b['modal_links'] ?? null) ? $b['modal_links'] : [];
        if (isset($b['url'])) {
            $b['url'] = $this->fixUrls((string) $b['url']);
        }

        return $b;
    }

    /** wpmedia:/wppost: markers → new references, recursively; strings get legacy links rewritten. */
    private function convert(mixed $value): mixed
    {
        if (is_array($value)) {
            return array_map(fn ($v) => $this->convert($v), $value);
        }
        if (is_string($value)) {
            if (str_starts_with($value, 'wpmedia:')) {
                return $this->mediaRef($value);
            }
            if (str_starts_with($value, 'wppost:')) {
                return $this->eventRef($value);
            }

            return $this->fixUrls($value);
        }

        return $value === false ? null : $value;
    }

    private function mediaRef(mixed $value): ?string
    {
        if (is_string($value) && str_starts_with($value, 'wpmedia:')) {
            return $this->media[(int) substr($value, 8)] ?? null;
        }

        return null;
    }

    private function eventRef(mixed $value): ?int
    {
        if (is_string($value) && str_starts_with($value, 'wppost:')) {
            $value = (int) substr($value, 7);
        }

        return is_numeric($value) ? ($this->events[(int) $value] ?? null) : null;
    }

    /** Links to the old WordPress site become paths on the new public site. */
    private function fixUrls(?string $text): ?string
    {
        if ($text === null || $this->legacyUrl === '') {
            return $text;
        }

        return preg_replace_callback('#'.preg_quote($this->legacyUrl, '#').'(/(?:event|e)/[a-z0-9-]+)/?#i', fn ($m) => $m[1], $text);
    }

    private function color(mixed $v): ?string
    {
        return is_string($v) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : null;
    }

    /** WordPress field names use dashes; the new form keys use underscores. */
    private function key(string $name): string
    {
        return Str::slug($name, '_');
    }

    /**
     * @param  class-string<Event|Microsite>  $model
     */
    private function uniqueSlug(string $model, string $slug, ?int $ignore): string
    {
        $base = Str::slug($slug) ?: 'item';
        $candidate = $base;
        $n = 2;
        while ($model::withTrashed()->where('slug', $candidate)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $candidate = $base.'-'.$n++;
        }
        if ($candidate !== $base) {
            $this->notes[] = "\"{$base}\" was already taken, so it was imported as \"{$candidate}\".";
        }

        return $candidate;
    }

    /**
     * @param  list<array<string, mixed>>  $applicants
     */
    private function importApplicants(array $applicants): void
    {
        foreach ($applicants as $a) {
            $eventId = $this->events[(int) $a['event_wp_id']] ?? null;
            if (! $eventId) {
                $this->notes[] = "Applicant #{$a['wp_id']} belongs to a WordPress event that was not exported, so it was skipped.";

                continue;
            }
            $event = Event::query()->find($eventId);
            $types = collect(FormSchema::dataFields($event))->pluck('type', 'name');
            $data = [];
            foreach ((array) $a['data'] as $k => $v) {
                $data[$this->key((string) $k)] = $v;
            }
            $labels = [];
            foreach ((array) $a['labels'] as $k => $v) {
                $labels[$this->key((string) $k)] = $v;
            }
            $email = null;
            $phone = null;
            foreach ($data as $k => $v) {
                if (! is_string($v) || $v === '') {
                    continue;
                }
                if (! $email && ($types[$k] ?? null) === 'email') {
                    $email = strtolower($v);
                }
                if (! $phone && ($types[$k] ?? null) === 'phone') {
                    $phone = $v;
                }
            }
            $src = (array) $a['source'];
            $source = array_filter([
                'utm_source' => $src['utm_source'] ?? null,
                'utm_medium' => $src['utm_medium'] ?? null,
                'utm_campaign' => $src['utm_campaign'] ?? null,
                'referrer' => $src['ref_domain'] ?? null,
            ]);

            $applicant = Applicant::withTrashed()->where('legacy_wp_id', $a['wp_id'])->first() ?? new Applicant(['legacy_wp_id' => $a['wp_id']]);
            $isNew = ! $applicant->exists;
            $applicant->fill([
                'event_id' => $eventId,
                'status' => array_key_exists($a['status'], Applicant::STATUSES) ? $a['status'] : 'registered',
                'name' => trim((string) preg_replace('/\bOthers: /', '', (string) $a['title'])) ?: null,
                'email' => $email,
                'phone' => $phone,
                'data' => $data,
                'labels' => $labels,
                'source' => $source ?: null,
                'visitor_id' => $a['visitor'] ?: null,
                'ip_hash' => $a['ip_hash'] ?: null,
                'notes' => $a['notes'] ?: null,
            ]);
            if ($isNew) {
                $applicant->created_at = Carbon::parse($a['created_at']);
            }
            $applicant->save();

            if ($isNew) {
                foreach ((array) $a['log'] as $entry) {
                    $applicant->activities()->create([
                        'message' => mb_substr((string) ($entry['m'] ?? ''), 0, 500),
                        'created_at' => isset($entry['t']) ? Carbon::createFromTimestamp((int) $entry['t']) : now(),
                    ]);
                }
                $applicant->activities()->create(['message' => 'Imported from the old WordPress site', 'created_at' => now()]);
            }
        }
    }
}
