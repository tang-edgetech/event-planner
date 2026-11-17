<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\User;
use App\Services\ApplicantStatusService;
use App\Services\EventMailer;
use App\Services\RegistrationService;
use App\Support\Audit;
use App\Support\Export;
use App\Support\FormSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class ApplicantController extends Controller
{
    public function __construct(private ApplicantStatusService $statuses) {}

    public function index(Request $request): JsonResponse
    {
        $page = $this->query($request)
            ->with('event:id,title,slug')
            ->orderBy($this->sortColumn($request), $request->input('order') === 'asc' ? 'asc' : 'desc')
            ->paginate(min(200, max(10, (int) $request->input('per_page', 25))));

        $mask = $this->masked($request->user());
        $event = $request->input('event_id') ? Event::query()->find($request->input('event_id')) : null;

        return response()->json([
            'data' => collect($page->items())->map(fn (Applicant $a) => $this->row($a, $mask))->all(),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'per_page' => $page->perPage()],
            'statuses' => collect(Applicant::STATUSES)->map(fn ($l, $v) => ['value' => $v, 'label' => $l])->values(),
            'event' => $event ? [
                'id' => $event->id,
                'title' => $event->title,
                'registration' => $event->registrationState(),
                'counts' => $event->applicants()->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status'),
                'columns' => collect(FormSchema::dataFields($event))->reject(fn ($f) => in_array($f['type'], ['hidden', 'consent'], true))->map(fn ($f) => ['name' => $f['name'], 'label' => strip_tags($f['label']) ?: $f['name']])->values(),
            ] : null,
        ]);
    }

    public function show(Request $request, Applicant $applicant): JsonResponse
    {
        $applicant->load(['event', 'activities.user:id,name']);
        $mask = $this->masked($request->user());
        $fields = $applicant->event ? FormSchema::dataFields($applicant->event) : [];

        return response()->json(['data' => [
            ...$this->row($applicant, $mask),
            'fields' => collect($fields)->map(fn ($f) => [
                'name' => $f['name'], 'type' => $f['type'], 'label' => strip_tags($f['label']) ?: $f['name'],
                'options' => $f['options'], 'required' => $f['required'], 'format' => $f['format'],
            ])->values(),
            'data' => $mask ? $this->maskData($applicant->data ?? [], $fields) : $applicant->data,
            'labels' => $applicant->labels,
            'source' => $applicant->source,
            'notes' => $applicant->notes,
            'activities' => $applicant->activities->map(fn ($a) => ['message' => $a->message, 'user' => $a->user?->name, 'created_at' => $a->created_at?->toIso8601String()]),
        ]]);
    }

    public function update(Request $request, Applicant $applicant, RegistrationService $registration): JsonResponse
    {
        $data = $request->validate([
            'data' => ['sometimes', 'array'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
        $changes = [];
        if (array_key_exists('data', $data) && $applicant->event) {
            $fields = FormSchema::dataFields($applicant->event);
            $fieldsNoUnique = array_map(fn ($f) => [...$f, 'unique' => false], $fields);
            [$clean, $errors] = $registration->validate($applicant->event, $fieldsNoUnique, $data['data']);
            foreach ($fields as $f) {
                $value = $clean[$f['name']] ?? '';
                if ($f['unique'] && is_string($value) && $value !== '' && Applicant::query()->where('event_id', $applicant->event_id)->where('id', '!=', $applicant->id)->where('status', '!=', 'cancelled')->where('data->'.$f['name'], $value)->exists()) {
                    $errors[$f['name']] = 'Another applicant already uses this value.';
                }
            }
            if ($errors) {
                throw ValidationException::withMessages(collect($errors)->mapWithKeys(fn ($m, $k) => ["data.{$k}" => $m])->all());
            }
            $old = $applicant->data ?? [];
            $diff = array_keys(array_filter($clean, fn ($v, $k) => ($old[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
            if ($diff) {
                $applicant->data = array_merge($old, $clean);
                $applicant->email = collect($fields)->first(fn ($f) => $f['type'] === 'email') ? ($clean[collect($fields)->first(fn ($f) => $f['type'] === 'email')['name']] ?? $applicant->email) : $applicant->email;
                $changes[] = 'answers ('.implode(', ', $diff).')';
                $applicant->log('Answers edited: '.implode(', ', $diff), $request->user()->id);
            }
        }
        if (array_key_exists('notes', $data) && $data['notes'] !== $applicant->notes) {
            $applicant->notes = $data['notes'];
            $changes[] = 'notes';
            $applicant->log('Notes updated', $request->user()->id);
        }
        $applicant->save();
        if ($changes) {
            Audit::log('applicant.updated', "{$request->user()->name} edited {$applicant->name}", "{$request->user()->name} changed the ".implode(' and ', $changes)." of {$applicant->name} for \"{$applicant->event?->title}\".", 'success', $applicant, ['changed' => $changes], null, $applicant->name ?: 'Applicant #'.$applicant->id);
        }

        return $this->show($request, $applicant->fresh());
    }

    public function status(Request $request, Applicant $applicant): JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(array_keys(Applicant::STATUSES))]]);
        $this->statuses->change($applicant, $data['status'], $request->user());

        return response()->json(['ok' => true, 'status' => $applicant->status]);
    }

    public function bulkStatus(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:2000'],
            'ids.*' => ['integer'],
            'status' => ['required', Rule::in(array_keys(Applicant::STATUSES))],
        ]);
        $changed = 0;
        foreach (Applicant::query()->whereIn('id', $data['ids'])->with('event')->get() as $applicant) {
            $changed += $this->statuses->change($applicant, $data['status'], $request->user(), false) ? 1 : 0;
        }
        $label = Applicant::STATUSES[$data['status']];
        Audit::log('applicant.bulk_status', "{$request->user()->name} set {$changed} applicant(s) to {$label}", "{$request->user()->name} selected ".count($data['ids'])." applicant(s) and changed their status to {$label}. {$changed} actually changed (the others already had that status).", 'success', null, ['ids' => $data['ids'], 'status' => $data['status']]);

        return response()->json(['ok' => true, 'changed' => $changed]);
    }

    public function resend(Request $request, Applicant $applicant, EventMailer $mailer): JsonResponse
    {
        $kind = $applicant->status === 'waitlist' ? 'waitlist' : 'registered';
        $ok = $mailer->confirmation($applicant->load('event'), $kind);
        if ($ok) {
            $applicant->log('Confirmation email sent again', $request->user()->id);
        }

        return response()->json(['ok' => $ok], $ok ? 200 : 422);
    }

    public function destroy(Request $request, Applicant $applicant): JsonResponse
    {
        $applicant->delete();
        Audit::log('applicant.deleted', "{$request->user()->name} deleted the applicant {$applicant->name}", "{$request->user()->name} deleted {$applicant->name}'s registration for \"{$applicant->event?->title}\".", 'success', $applicant, [], null, $applicant->name ?: 'Applicant #'.$applicant->id);

        return response()->json(['ok' => true]);
    }

    public function export(Request $request): Response
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(['csv', 'json'])],
            'ids' => ['nullable', 'array', 'max:50000'],
            'ids.*' => ['integer'],
        ]);
        $query = $this->query($request)->with('event:id,title');
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $applicants = $query->orderBy('id')->limit(50000)->get();
        $mask = $this->masked($request->user());

        $columns = ['id' => 'ID', 'event' => 'Event', 'status' => 'Status', 'name' => 'Name', 'email' => 'Email', 'phone' => 'Phone', 'registered_at' => 'Registered at'];
        $event = $request->input('event_id') ? Event::query()->find($request->input('event_id')) : null;
        $fields = $event ? FormSchema::dataFields($event) : [];
        foreach ($fields as $f) {
            $columns['f_'.$f['name']] = strip_tags($f['label']) ?: $f['name'];
        }
        $columns += ['source' => 'Source', 'notes' => 'Notes'];

        $rows = $applicants->map(function (Applicant $a) use ($fields, $mask) {
            $row = $this->row($a, $mask);
            $answers = $mask ? $this->maskData($a->data ?? [], $fields) : ($a->data ?? []);
            $out = [
                'id' => $a->id, 'event' => $a->event?->title, 'status' => Applicant::STATUSES[$a->status] ?? $a->status,
                'name' => $row['name'], 'email' => $row['email'], 'phone' => $row['phone'],
                'registered_at' => $a->created_at?->format('Y-m-d H:i'),
                'source' => $this->sourceLabel($a->source),
                'notes' => $a->notes,
                'answers' => $answers,
            ];
            foreach ($fields as $f) {
                $out['f_'.$f['name']] = $answers[$f['name']] ?? '';
            }

            return $out;
        })->all();

        Audit::log('export.applicants', "{$request->user()->name} exported ".count($rows).' applicant(s)', "{$request->user()->name} downloaded ".count($rows).' applicant record(s)'.($event ? " of \"{$event->title}\"" : ' across all events').' as '.strtoupper($data['format']).(! empty($data['ids']) ? ' (selected rows only)' : '').($mask ? '. Personal details were masked.' : '.'), 'success', $event, ['format' => $data['format'], 'count' => count($rows)]);

        return Export::download($rows, $columns, 'applicants-'.($event?->slug ?? 'all').'-'.now()->format('Ymd-His'), $data['format']);
    }

    /**
     * @return Builder<Applicant>
     */
    private function query(Request $request): Builder
    {
        return Applicant::query()
            ->when($request->input('event_id'), fn ($q, $id) => $q->where('event_id', $id))
            ->when($request->input('status'), fn ($q, $s) => $q->whereIn('status', (array) $s))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()))
            ->when($request->string('q')->trim()->value(), function ($q, string $term) {
                $q->where(fn ($w) => $w->where('name', 'like', "%{$term}%")
                    ->orWhere('email', 'like', "%{$term}%")
                    ->orWhere('phone', 'like', "%{$term}%")
                    ->orWhere('data', 'like', '%'.addcslashes($term, '%_').'%'));
            });
    }

    private function sortColumn(Request $request): string
    {
        return in_array($request->input('sort'), ['name', 'email', 'status', 'created_at'], true) ? $request->input('sort') : 'created_at';
    }

    private function masked(?User $user): bool
    {
        return (bool) setting('extra', 'mask_viewers', false) && $user && ! $user->hasPermission('applicants.manage');
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Applicant $a, bool $mask): array
    {
        return [
            'id' => $a->id,
            'event_id' => $a->event_id,
            'event_title' => $a->event?->title,
            'status' => $a->status,
            'status_label' => Applicant::STATUSES[$a->status] ?? $a->status,
            'name' => $a->name,
            'email' => $mask ? $this->maskValue((string) $a->email, 'email') : $a->email,
            'phone' => $mask ? $this->maskValue((string) $a->phone, 'phone') : $a->phone,
            'source' => $this->sourceLabel($a->source),
            'answers' => $mask ? null : $a->data,
            'created_at' => $a->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<array<string, mixed>>  $fields
     * @return array<string, mixed>
     */
    private function maskData(array $data, array $fields): array
    {
        foreach ($fields as $f) {
            if (in_array($f['type'], ['email', 'phone', 'nric'], true) && isset($data[$f['name']]) && is_string($data[$f['name']])) {
                $data[$f['name']] = $this->maskValue($data[$f['name']], $f['type']);
            }
        }

        return $data;
    }

    private function maskValue(string $value, string $type): string
    {
        if ($value === '') {
            return '';
        }
        if ($type === 'email' && str_contains($value, '@')) {
            [$user, $domain] = explode('@', $value, 2);

            return mb_substr($user, 0, 1).str_repeat('•', max(2, mb_strlen($user) - 1)).'@'.$domain;
        }

        return str_repeat('•', max(0, mb_strlen($value) - 4)).mb_substr($value, -4);
    }

    /**
     * @param  array<string, mixed>|null  $source
     */
    private function sourceLabel(?array $source): string
    {
        if (! $source) {
            return 'Direct';
        }
        $parts = array_filter([$source['utm_source'] ?? null, $source['utm_medium'] ?? null, $source['utm_campaign'] ?? null]);

        return $parts ? implode(' / ', $parts) : (($source['referrer'] ?? '') ?: 'Direct');
    }
}
