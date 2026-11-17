<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Microsite;
use App\Models\PageView;
use App\Services\RegistrationService;
use App\Support\FieldValidator;
use App\Support\FormSchema;
use App\Support\Placeholders;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Endpoints for the public site's server (never called by browsers directly).
 * Visitor details (IP, browser, referrer, UTM tags) arrive in X-Client-* headers set by that server.
 */
class PublicController extends Controller
{
    public function site(): JsonResponse
    {
        return response()->json([
            'site_name' => setting('general', 'site_name'),
            'home_mode' => setting('extra', 'home_mode', 'maintenance'),
            'maintenance_title' => setting('extra', 'maintenance_title'),
            'maintenance_message' => setting('extra', 'maintenance_message'),
            'contact_email' => setting('general', 'contact_email'),
        ]);
    }

    public function event(Request $request, string $slug): JsonResponse
    {
        $event = Event::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();
        if ($request->boolean('track')) {
            $this->track($request, 'event', $event->id);
        }

        return response()->json($this->eventPayload($event));
    }

    public function register(Request $request, string $slug, RegistrationService $service): JsonResponse
    {
        $event = Event::query()->where('slug', $slug)->where('status', 'published')->firstOrFail();
        $source = json_decode((string) $request->header('X-Client-Source', ''), true);
        $result = $service->process($event, $request->all(), [
            'ip' => $this->clientIp($request),
            'visitor' => (string) $request->header('X-Client-Visitor', ''),
            'source' => is_array($source) ? array_map(fn ($v) => mb_substr(strip_tags((string) $v), 0, 190), array_slice($source, 0, 10)) : null,
        ]);

        return response()->json($result, $result['ok'] ? 200 : 422);
    }

    public function microsite(Request $request, string $slug): JsonResponse
    {
        $preview = null;
        if ($token = $request->query('preview')) {
            $preview = Cache::get('microsite-preview:'.$token);
        }
        $site = Microsite::query()->where('slug', $slug)
            ->when(! $preview, fn ($q) => $q->where('status', 'published'))
            ->firstOrFail();
        if ($preview && (int) $preview['id'] !== $site->id) {
            abort(404);
        }

        $title = $preview['title'] ?? $site->title;
        $sections = $preview['sections'] ?? $site->sections ?? [];
        $settings = $preview['settings'] ?? $site->settings ?? [];
        $eventId = $preview ? $preview['event_id'] : $site->event_id;

        if (! $preview && $request->boolean('track')) {
            $this->track($request, 'microsite', $site->id);
        }

        // Events used by registration-form sections and "event" buttons.
        $eventIds = [$eventId];
        array_walk_recursive($sections, function ($value, $key) use (&$eventIds) {
            if ($key === 'event_id' && $value) {
                $eventIds[] = (int) $value;
            }
        });
        $events = Event::query()->whereIn('id', array_filter($eventIds))->where('status', 'published')->get()
            ->mapWithKeys(fn (Event $e) => [$e->id => $this->eventPayload($e)]);

        return response()->json([
            'id' => $site->id,
            'title' => $title,
            'slug' => $site->slug,
            'preview' => (bool) $preview,
            'event_id' => $eventId,
            'sections' => array_values(array_filter($sections, fn ($s) => empty($s['settings']['hidden']))),
            'settings' => $settings,
            'events' => $events,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function eventPayload(Event $event): array
    {
        $state = $event->registrationState();
        $fields = FormSchema::forEvent($event);

        return [
            'id' => $event->id,
            'title' => $event->title,
            'slug' => $event->slug,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'date_text' => $event->date_text ?: $event->starts_at?->format('l, j F Y'),
            'time_text' => $event->time_text ?: ($event->starts_at ? $event->starts_at->format('g:i A').($event->ends_at ? ' – '.$event->ends_at->format('g:i A') : '') : null),
            'venue' => $event->venue,
            'venue_map_url' => $event->venue_map_url,
            'summary' => $event->summary,
            'banner_desktop' => $event->banner_desktop,
            'banner_mobile' => $event->banner_mobile,
            'accent_color' => $event->accent_color,
            'page_bg' => $event->page_bg,
            'page_layout' => $event->page_layout,
            'show_title' => $event->show_title,
            'registration' => [
                'state' => $state['state'],
                'label' => $state['label'],
                'remaining' => $state['remaining'],
            ],
            'closed' => in_array($state['state'], ['open', 'waitlist'], true) ? null : $this->closedContent($event, $state['state']),
            'form' => in_array($state['state'], ['open', 'waitlist'], true) ? [
                'intro' => $event->form_intro,
                'fields' => array_map(fn ($f) => array_diff_key($f, array_flip(['unique', 'options_source', 'preset'])), $fields),
                'submit_label' => $event->submit_label ?: 'Submit',
                'waitlist_message' => $state['state'] === 'waitlist' ? ($event->waitlist_message ?: 'This event is currently full. You may still register and you will be placed on the waiting list.') : null,
                'token' => RegistrationService::token($event),
                'patterns' => FieldValidator::patterns(),
            ] : null,
        ];
    }

    /**
     * @return array{title: string, html: string}
     */
    private function closedContent(Event $event, string $state): array
    {
        [$title, $html] = match ($state) {
            'full' => [$event->full_title ?: 'Application Full', $event->full_message ?: '<p>We have reached the maximum registration capacity and are no longer able to accept registrations. Thank you for your interest.</p>'],
            'not_open' => ['Registration opens soon', '<p>Registration will open on '.e($event->reg_open_at?->format('j F Y, g:i A') ?? 'a later date').'.</p>'],
            default => [$event->closed_title ?: 'Registration Closed', $event->closed_message ?: '<p>Registration for this event is now closed.</p>'],
        };

        return ['title' => strip_tags(Placeholders::replace($title, $event)), 'html' => Placeholders::replace($html, $event)];
    }

    private function track(Request $request, string $type, int $id): void
    {
        $ua = (string) $request->header('X-Client-Agent', '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|preview|facebookexternalhit|whatsapp/i', $ua)) {
            return;
        }
        $source = json_decode((string) $request->header('X-Client-Source', ''), true) ?: [];
        $referrer = (string) ($source['referrer'] ?? '');
        PageView::query()->create([
            'viewable_type' => $type,
            'viewable_id' => $id,
            'visitor_hash' => hash_hmac('sha256', $this->clientIp($request).'|'.$ua.'|'.now()->toDateString(), (string) config('app.key')),
            'referrer_host' => $referrer !== '' ? mb_substr((string) (parse_url($referrer, PHP_URL_HOST) ?: $referrer), 0, 190) : null,
            'utm_source' => isset($source['utm_source']) ? mb_substr((string) $source['utm_source'], 0, 100) : null,
            'utm_medium' => isset($source['utm_medium']) ? mb_substr((string) $source['utm_medium'], 0, 100) : null,
            'utm_campaign' => isset($source['utm_campaign']) ? mb_substr((string) $source['utm_campaign'], 0, 100) : null,
            'device' => preg_match('/iPad|Tablet/i', $ua) ? 'tablet' : (preg_match('/Mobile|Android|iPhone/i', $ua) ? 'mobile' : 'desktop'),
            'created_at' => now(),
        ]);
    }

    private function clientIp(Request $request): string
    {
        $ip = (string) $request->header('X-Client-Ip', '');

        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : (string) $request->ip();
    }
}
