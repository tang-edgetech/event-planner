<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Applicant;
use App\Models\Event;
use App\Models\Microsite;
use App\Models\PageView;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    /** Overview (dashboard): headline numbers, upcoming events, latest registrations. */
    public function dashboard(): JsonResponse
    {
        $monthStart = now()->startOfMonth();
        $upcoming = Event::query()->where('status', 'published')
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '>=', now()->startOfDay()))
            ->orderByRaw('starts_at IS NULL')->orderBy('starts_at')->limit(6)->get();

        return response()->json([
            'kpis' => [
                'open_events' => Event::query()->where('status', 'published')->get()->filter(fn (Event $e) => $e->isAccepting())->count(),
                'published_events' => Event::query()->where('status', 'published')->count(),
                'applicants_total' => Applicant::query()->count(),
                'applicants_month' => Applicant::query()->where('created_at', '>=', $monthStart)->count(),
                'waitlist' => Applicant::query()->where('status', 'waitlist')->count(),
                'microsites' => Microsite::query()->where('status', 'published')->count(),
                'views_month' => PageView::query()->where('created_at', '>=', $monthStart)->count(),
            ],
            'upcoming' => $upcoming->map(fn (Event $e) => [
                'id' => $e->id, 'title' => $e->title, 'slug' => $e->slug, 'starts_at' => $e->starts_at?->toIso8601String(),
                'date_text' => $e->date_text, 'venue' => $e->venue, 'registration' => $e->registrationState(),
            ]),
            'latest' => Applicant::query()->with('event:id,title')->latest('id')->limit(8)->get()->map(fn (Applicant $a) => [
                'id' => $a->id, 'name' => $a->name, 'event_title' => $a->event?->title, 'status' => $a->status,
                'status_label' => Applicant::STATUSES[$a->status] ?? $a->status, 'created_at' => $a->created_at?->toIso8601String(),
            ]),
            'trend' => $this->daily(Applicant::query(), now()->subDays(13)->startOfDay(), now()->endOfDay()),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $eventId = $request->integer('event_id') ?: null;

        $applicants = Applicant::query()->whereBetween('created_at', [$from, $to])->when($eventId, fn ($q) => $q->where('event_id', $eventId));
        $views = PageView::query()->whereBetween('created_at', [$from, $to])
            ->when($eventId, fn ($q) => $q->where(fn ($w) => $w->where(['viewable_type' => 'event', 'viewable_id' => $eventId])
                ->orWhere(fn ($m) => $m->where('viewable_type', 'microsite')->whereIn('viewable_id', Microsite::query()->where('event_id', $eventId)->pluck('id')))));

        $registrations = (clone $applicants)->count();
        $viewCount = (clone $views)->count();
        $visitors = (clone $views)->distinct()->count('visitor_hash');

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'totals' => [
                'registrations' => $registrations,
                'views' => $viewCount,
                'visitors' => $visitors,
                'conversion' => $visitors ? round($registrations * 100 / $visitors, 1) : null,
            ],
            'registrations_daily' => $this->daily(clone $applicants, $from, $to),
            'views_daily' => $this->daily(clone $views, $from, $to),
            'by_status' => (clone $applicants)->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')
                ->map(fn ($n, $s) => ['status' => $s, 'label' => Applicant::STATUSES[$s] ?? $s, 'count' => (int) $n])->values(),
            'by_device' => (clone $views)->selectRaw("COALESCE(device, 'unknown') as device, count(*) as n")->groupBy('device')->pluck('n', 'device'),
        ]);
    }

    public function events(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $events = Event::query()->withCount([
            'applicants as total' => fn ($q) => $q->whereBetween('created_at', [$from, $to]),
            'applicants as seated' => fn ($q) => $q->whereIn('status', Event::SEATED),
            'applicants as waitlist' => fn ($q) => $q->where('status', 'waitlist'),
            'applicants as attended' => fn ($q) => $q->where('status', 'attended'),
            'applicants as cancelled' => fn ($q) => $q->where('status', 'cancelled'),
        ])->orderByDesc('starts_at')->get();
        $views = PageView::query()->where('viewable_type', 'event')->whereBetween('created_at', [$from, $to])
            ->selectRaw('viewable_id, count(*) as n')->groupBy('viewable_id')->pluck('n', 'viewable_id');

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'data' => $events->map(fn (Event $e) => [
                'id' => $e->id, 'title' => $e->title, 'status' => $e->status, 'starts_at' => $e->starts_at?->toIso8601String(),
                'capacity' => $e->capacity_max, 'registrations' => (int) $e->total, 'seated' => (int) $e->seated,
                'waitlist' => (int) $e->waitlist, 'attended' => (int) $e->attended, 'cancelled' => (int) $e->cancelled,
                'fill_rate' => $e->capacity_max ? round($e->seated * 100 / $e->capacity_max, 1) : null,
                'attendance_rate' => $e->seated ? round($e->attended * 100 / $e->seated, 1) : null,
                'views' => (int) ($views[$e->id] ?? 0),
            ]),
        ]);
    }

    public function sources(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);
        $eventId = $request->integer('event_id') ?: null;
        $applicants = Applicant::query()->whereBetween('created_at', [$from, $to])->when($eventId, fn ($q) => $q->where('event_id', $eventId))->get(['source']);

        $bySource = [];
        $byCampaign = [];
        foreach ($applicants as $a) {
            $s = $a->source ?? [];
            $source = ($s['utm_source'] ?? '') ?: (($s['referrer'] ?? '') ?: 'Direct');
            $bySource[$source] = ($bySource[$source] ?? 0) + 1;
            if (! empty($s['utm_campaign'])) {
                $byCampaign[$s['utm_campaign']] = ($byCampaign[$s['utm_campaign']] ?? 0) + 1;
            }
        }
        arsort($bySource);
        arsort($byCampaign);

        $referrers = PageView::query()->whereBetween('created_at', [$from, $to])
            ->selectRaw("COALESCE(NULLIF(utm_source, ''), NULLIF(referrer_host, ''), 'Direct') as src, count(*) as n")
            ->groupBy('src')->orderByDesc('n')->limit(20)->pluck('n', 'src');

        return response()->json([
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'registrations_by_source' => collect($bySource)->map(fn ($n, $k) => ['source' => $k, 'count' => $n])->values(),
            'registrations_by_campaign' => collect($byCampaign)->map(fn ($n, $k) => ['campaign' => $k, 'count' => $n])->values(),
            'views_by_source' => collect($referrers)->map(fn ($n, $k) => ['source' => $k, 'count' => (int) $n])->values(),
        ]);
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function range(Request $request): array
    {
        $to = $request->input('to') ? Carbon::parse($request->input('to'))->endOfDay() : now()->endOfDay();
        $from = $request->input('from') ? Carbon::parse($request->input('from'))->startOfDay() : $to->copy()->subDays(29)->startOfDay();

        return [$from, $to];
    }

    /**
     * @return list<array{date: string, count: int}>
     */
    private function daily(mixed $query, Carbon $from, Carbon $to): array
    {
        $counts = $query->whereBetween('created_at', [$from, $to])
            ->selectRaw('DATE(created_at) as d, count(*) as n')->groupBy(DB::raw('DATE(created_at)'))->pluck('n', 'd');
        $out = [];
        for ($day = $from->copy()->startOfDay(); $day->lte($to); $day->addDay()) {
            $key = $day->toDateString();
            $out[] = ['date' => $key, 'count' => (int) ($counts[$key] ?? 0)];
        }

        return $out;
    }
}
