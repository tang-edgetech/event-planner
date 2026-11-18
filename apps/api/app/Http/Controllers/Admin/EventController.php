<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Event;
use App\Support\Audit;
use App\Support\FieldValidator;
use App\Support\FormSchema;
use App\Support\Html;
use App\Support\Sorting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    /** Rich-text fields cleaned before saving. */
    private const HTML_FIELDS = ['summary', 'form_intro', 'full_message', 'closed_message', 'waitlist_confirmation', 'confirmation_message', 'email_body'];

    /** @var array<string, string> */
    private const FIELD_LABELS = [
        'title' => 'title', 'slug' => 'web address', 'status' => 'status', 'starts_at' => 'start', 'ends_at' => 'end',
        'date_text' => 'date text', 'time_text' => 'time text', 'venue' => 'venue', 'venue_map_url' => 'map link', 'summary' => 'description',
        'banner_desktop' => 'desktop banner', 'banner_mobile' => 'mobile banner', 'accent_color' => 'accent colour', 'page_bg' => 'page background',
        'page_layout' => 'page layout', 'show_title' => 'title visibility', 'form_intro' => 'form intro', 'form_fields' => 'registration form fields',
        'submit_label' => 'submit button', 'reg_status' => 'registration status', 'capacity_min' => 'minimum capacity', 'capacity_max' => 'maximum capacity',
        'reg_open_at' => 'registration opening', 'reg_close_at' => 'registration closing', 'enable_waitlist' => 'waiting list',
        'default_status' => 'default applicant status', 'full_title' => '"full" heading', 'full_message' => '"full" message',
        'closed_title' => '"closed" heading', 'closed_message' => '"closed" message', 'waitlist_message' => 'waiting list notice',
        'waitlist_confirmation' => 'waiting list confirmation', 'confirmation_type' => 'after-submit action', 'confirmation_message' => 'thank-you message',
        'redirect_url' => 'redirect address', 'notify_admin' => 'admin notification', 'admin_emails' => 'notification recipients',
        'notify_applicant' => 'applicant confirmation email', 'email_subject' => 'email subject', 'email_body' => 'email body',
    ];

    public function index(Request $request): JsonResponse
    {
        $events = Event::query()
            ->when($request->string('q')->trim()->value(), fn ($q, $t) => $q->where(fn ($w) => $w->where('title', 'like', "%{$t}%")->orWhere('venue', 'like', "%{$t}%")->orWhere('slug', 'like', "%{$t}%")))
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('from'), fn ($q, $d) => $q->where('starts_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($request->input('to'), fn ($q, $d) => $q->where('starts_at', '<=', Carbon::parse($d)->endOfDay()))
            ->withCount([
                'applicants as applicants_count',
                'applicants as seated_count' => fn ($q) => $q->whereIn('status', Event::SEATED),
                'applicants as waitlist_count' => fn ($q) => $q->where('status', 'waitlist'),
            ]);
        if (! Sorting::apply($events, $request, ['title' => 'title', 'starts_at' => 'starts_at', 'status' => 'status', 'seated_count' => 'seated_count', 'applicants_count' => 'applicants_count', 'created_at' => 'created_at'])) {
            $events->orderByRaw('starts_at IS NULL')->orderByDesc('starts_at')->orderByDesc('id');
        }
        $events = $events->paginate(min(100, max(5, (int) $request->input('per_page', 20))));

        return response()->json([
            'data' => collect($events->items())->map(fn (Event $e) => $this->summary($e))->all(),
            'meta' => ['total' => $events->total(), 'page' => $events->currentPage(), 'per_page' => $events->perPage()],
        ]);
    }

    /** Short list for dropdowns (applicant filter, microsite linked event). */
    public function options(): JsonResponse
    {
        return response()->json(['data' => Event::query()->orderByDesc('starts_at')->get(['id', 'title', 'status', 'starts_at'])
            ->map(fn (Event $e) => ['value' => $e->id, 'label' => $e->title, 'status' => $e->status])]);
    }

    /** Field types and saved option lists for the form builder. */
    public function builder(): JsonResponse
    {
        return response()->json([
            'types' => collect(FormSchema::TYPES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'presets' => collect((array) setting('extra', 'option_lists', []))->map(fn ($l) => ['value' => $l['slug'], 'label' => $l['name'], 'count' => count($l['options'] ?? [])])->values(),
            'patterns' => FieldValidator::patterns(),
        ]);
    }

    public function show(Event $event): JsonResponse
    {
        return response()->json(['data' => $this->detail($event)]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate($this->rules(null, true));
        $data['slug'] = $this->uniqueSlug($data['slug'] ?? '' ?: $data['title']);
        $data['form_fields'] = $data['form_fields'] ?? $this->defaultFields();
        $data = $this->prepare($data);
        $data['created_by'] = $request->user()->id;

        $event = Event::query()->create($data);
        Audit::log('event.created', "{$request->user()->name} created the event \"{$event->title}\"", "{$request->user()->name} created a new event \"{$event->title}\" (status: {$event->status}). Its public address is /event/{$event->slug}.", 'success', $event);

        return response()->json(['data' => $this->detail($event)], 201);
    }

    public function update(Request $request, Event $event): JsonResponse
    {
        $data = $request->validate($this->rules($event));
        if (array_key_exists('slug', $data)) {
            $data['slug'] = $this->uniqueSlug($data['slug'] ?: ($data['title'] ?? $event->title), $event->id);
        }
        $data = $this->prepare($data);
        $event->fill($data);
        $changed = array_keys($event->getDirty());
        $event->save();

        if ($changed) {
            $names = array_map(fn ($c) => self::FIELD_LABELS[$c] ?? str_replace('_', ' ', $c), $changed);
            Audit::log('event.updated', "{$request->user()->name} updated the event \"{$event->title}\"", "{$request->user()->name} changed the ".implode(', ', $names)." of the event \"{$event->title}\".", 'success', $event, ['changed' => $changed]);
        }

        return response()->json(['data' => $this->detail($event->fresh())]);
    }

    public function duplicate(Request $request, Event $event): JsonResponse
    {
        $copy = $event->replicate(['capacity_alert_sent_at']);
        $copy->title = $event->title.' (copy)';
        $copy->slug = $this->uniqueSlug($event->slug.'-copy');
        $copy->status = 'draft';
        $copy->created_by = $request->user()->id;
        $copy->save();
        Audit::log('event.duplicated', "{$request->user()->name} duplicated \"{$event->title}\"", "{$request->user()->name} made a copy of \"{$event->title}\" with all its settings and form fields. The copy is a draft called \"{$copy->title}\"; applicants were not copied.", 'success', $copy);

        return response()->json(['data' => $this->detail($copy)], 201);
    }

    public function destroy(Request $request, Event $event): JsonResponse
    {
        $count = $event->applicants()->count();
        $event->delete();
        Audit::log('event.deleted', "{$request->user()->name} deleted the event \"{$event->title}\"", "{$request->user()->name} deleted the event \"{$event->title}\". It is no longer shown on the public site; its {$count} applicant(s) are kept for records.", 'success', $event);

        return response()->json(['ok' => true]);
    }

    /** Publish, unpublish or delete several events at once. */
    public function bulk(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'action' => ['required', Rule::in(['publish', 'draft', 'delete'])],
        ]);
        $events = Event::query()->whereIn('id', $data['ids'])->get();
        $me = $request->user()->name;
        $changed = 0;
        foreach ($events as $event) {
            if ($data['action'] === 'delete') {
                $event->delete();
                $changed++;
            } elseif ($event->status !== ($status = $data['action'] === 'publish' ? 'published' : 'draft')) {
                $event->forceFill(['status' => $status])->save();
                $changed++;
            }
        }
        $titles = $events->pluck('title')->map(fn ($t) => "\"{$t}\"")->implode(', ');
        [$verb, $effect] = match ($data['action']) {
            'publish' => ['published', 'Visitors can now open their registration pages.'],
            'draft' => ['unpublished', 'They are back to Draft and no longer open to visitors.'],
            'delete' => ['deleted', 'They are no longer shown on the public site; their applicants are kept for records.'],
        };
        Audit::log("event.bulk_{$data['action']}", "{$me} {$verb} {$changed} event(s)", "{$me} selected {$events->count()} event(s) ({$titles}) and {$verb} them. {$effect}", 'success', null, ['ids' => $events->pluck('id')->all()]);

        return response()->json(['ok' => true, 'changed' => $changed]);
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(?Event $event, bool $creating = false): array
    {
        $req = $creating ? 'required' : 'sometimes';
        $date = ['sometimes', 'nullable', 'date'];
        $text = ['sometimes', 'nullable', 'string', 'max:255'];
        $long = ['sometimes', 'nullable', 'string', 'max:100000'];

        return [
            'title' => [$req, 'string', 'max:255'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'],
            'status' => ['sometimes', Rule::in(['draft', 'published'])],
            'starts_at' => $date, 'ends_at' => [...$date, 'after_or_equal:starts_at'],
            'date_text' => $text, 'time_text' => $text, 'venue' => $text,
            'venue_map_url' => ['sometimes', 'nullable', 'url', 'max:1000'],
            'summary' => $long,
            'banner_desktop' => $text, 'banner_mobile' => $text,
            'accent_color' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'page_bg' => ['sometimes', 'nullable', 'regex:/^#[0-9a-fA-F]{3,8}$/'],
            'page_layout' => ['sometimes', Rule::in(['split', 'single'])],
            'show_title' => ['sometimes', 'boolean'],
            'form_intro' => $long,
            'form_fields' => ['sometimes', 'nullable', 'array', 'max:100'],
            'form_fields.*.type' => ['required', Rule::in(array_keys(FormSchema::TYPES))],
            'form_fields.*.label' => ['nullable', 'string', 'max:500'],
            'submit_label' => ['sometimes', 'nullable', 'string', 'max:60'],
            'reg_status' => ['sometimes', Rule::in(['auto', 'open', 'full', 'closed'])],
            'capacity_min' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'capacity_max' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:1000000'],
            'reg_open_at' => $date, 'reg_close_at' => $date,
            'enable_waitlist' => ['sometimes', 'boolean'],
            'default_status' => ['sometimes', Rule::in(['registered', 'confirmed'])],
            'full_title' => $text, 'full_message' => $long, 'closed_title' => $text, 'closed_message' => $long,
            'waitlist_message' => ['sometimes', 'nullable', 'string', 'max:2000'], 'waitlist_confirmation' => $long,
            'confirmation_type' => ['sometimes', Rule::in(['message', 'redirect'])],
            'confirmation_message' => $long,
            'redirect_url' => ['sometimes', 'nullable', 'url', 'max:1000'],
            'notify_admin' => ['sometimes', 'boolean'],
            'admin_emails' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'notify_applicant' => ['sometimes', 'boolean'],
            'email_subject' => $text, 'email_body' => $long,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function prepare(array $data): array
    {
        foreach (self::HTML_FIELDS as $f) {
            if (array_key_exists($f, $data)) {
                $data[$f] = Html::clean($data[$f]);
            }
        }
        if (array_key_exists('form_fields', $data)) {
            $data['form_fields'] = array_map(function ($f) {
                if (in_array($f['type'], ['heading', 'html', 'consent'], true) && isset($f['content'])) {
                    $f['content'] = Html::clean((string) $f['content']);
                }

                return $f;
            }, FormSchema::normalise($data['form_fields'] ?? []));
        }

        return $data;
    }

    private function uniqueSlug(string $source, ?int $ignore = null): string
    {
        $base = Str::slug($source) ?: 'event';
        $slug = $base;
        $n = 2;
        while (Event::withTrashed()->where('slug', $slug)->when($ignore, fn ($q) => $q->where('id', '!=', $ignore))->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function defaultFields(): array
    {
        return [
            ['type' => 'salutation', 'label' => 'Salutation', 'required' => true, 'width' => '25'],
            ['type' => 'text', 'label' => 'Full name', 'name' => 'full_name', 'required' => true, 'width' => '66'],
            ['type' => 'email', 'label' => 'Email', 'name' => 'email', 'required' => true, 'width' => '50', 'unique' => true],
            ['type' => 'phone', 'label' => 'Mobile number', 'name' => 'phone', 'required' => true, 'width' => '50', 'phone_format' => 'my'],
            ['type' => 'consent', 'label' => 'I agree to the processing of my personal data for this event.', 'name' => 'consent', 'required' => true, 'width' => '100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function summary(Event $e): array
    {
        $state = $e->registrationState();

        return [
            'id' => $e->id,
            'title' => $e->title,
            'slug' => $e->slug,
            'status' => $e->status,
            'starts_at' => $e->starts_at?->toIso8601String(),
            'date_text' => $e->date_text,
            'venue' => $e->venue,
            'capacity_max' => $e->capacity_max,
            'applicants_count' => (int) ($e->applicants_count ?? 0),
            'seated_count' => (int) ($e->seated_count ?? $state['count']),
            'waitlist_count' => (int) ($e->waitlist_count ?? 0),
            'registration' => $state,
            'updated_at' => $e->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detail(Event $e): array
    {
        $data = $e->toArray();
        foreach (['starts_at', 'ends_at', 'reg_open_at', 'reg_close_at'] as $d) {
            $data[$d] = $e->{$d}?->toIso8601String();
        }
        $data['form_fields'] = FormSchema::toBuilderRows($e->form_fields ?? []);
        $data['registration'] = $e->registrationState();
        $data['counts'] = [
            'total' => $e->applicants()->count(),
            'by_status' => $e->applicants()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
        ];

        return $data;
    }
}
