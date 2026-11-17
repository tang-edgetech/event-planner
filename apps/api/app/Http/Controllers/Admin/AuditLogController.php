<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\Audit;
use App\Support\Export;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = $this->query($request)->orderByDesc('id')
            ->paginate(min(200, max(10, (int) $request->input('per_page', 25))));

        /** @var User $me */
        $me = $request->user();

        return response()->json([
            'data' => collect($logs->items())->map(fn (AuditLog $l) => $this->transform($l))->all(),
            'meta' => ['total' => $logs->total(), 'page' => $logs->currentPage(), 'per_page' => $logs->perPage()],
            'filters' => [
                'categories' => collect(Audit::CATEGORIES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values(),
                'users' => User::query()->visibleTo($me)->orderBy('name')->get(['id', 'name'])->map(fn ($u) => ['value' => $u->id, 'label' => $u->name]),
            ],
        ]);
    }

    /** Export the selected rows (ids) or everything matching the filters, as CSV or JSON. */
    public function export(Request $request): Response
    {
        $data = $request->validate([
            'format' => ['required', Rule::in(['csv', 'json'])],
            'ids' => ['nullable', 'array', 'max:10000'],
            'ids.*' => ['integer'],
        ]);

        $query = $this->query($request);
        if (! empty($data['ids'])) {
            $query->whereIn('id', $data['ids']);
        }
        $rows = $query->orderByDesc('id')->limit(50000)->get()->map(fn (AuditLog $l) => $this->transform($l))->all();

        Audit::log('export.audit_logs', "{$request->user()->name} exported ".count($rows).' audit log entries', "{$request->user()->name} downloaded ".count($rows).' audit log entries as '.strtoupper($data['format']).(! empty($data['ids']) ? ' (selected rows only).' : ' (all rows matching the filters).'), 'success', null, ['format' => $data['format'], 'count' => count($rows)]);

        $columns = ['id' => 'ID', 'created_at' => 'Date & time', 'status' => 'Result', 'category_label' => 'Area', 'action' => 'Action', 'user_name' => 'By', 'summary' => 'Summary', 'description' => 'What happened', 'subject_label' => 'Item', 'ip_address' => 'IP address', 'user_agent' => 'Browser'];

        return Export::download($rows, $columns, 'audit-logs-'.now()->format('Ymd-His'), $data['format']);
    }

    /**
     * @return Builder<AuditLog>
     */
    private function query(Request $request): Builder
    {
        /** @var User $me */
        $me = $request->user();

        return AuditLog::query()
            ->visibleTo($me)
            ->when($request->string('q')->trim()->value(), function ($q, string $term) {
                $q->where(fn ($w) => $w->where('summary', 'like', "%{$term}%")
                    ->orWhere('description', 'like', "%{$term}%")
                    ->orWhere('user_name', 'like', "%{$term}%")
                    ->orWhere('subject_label', 'like', "%{$term}%")
                    ->orWhere('ip_address', 'like', "%{$term}%"));
            })
            ->when($request->input('status'), fn ($q, $s) => $q->where('status', $s))
            ->when($request->input('category'), fn ($q, $c) => $q->whereIn('category', (array) $c))
            ->when($request->input('user_id'), fn ($q, $u) => $q->where('user_id', $u))
            ->when($request->input('from'), fn ($q, $d) => $q->where('created_at', '>=', Carbon::parse($d)->startOfDay()))
            ->when($request->input('to'), fn ($q, $d) => $q->where('created_at', '<=', Carbon::parse($d)->endOfDay()));
    }

    /**
     * @return array<string, mixed>
     */
    private function transform(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'created_at' => $log->created_at?->toIso8601String(),
            'status' => $log->status,
            'category' => $log->category,
            'category_label' => Audit::CATEGORIES[$log->category] ?? ucfirst($log->category),
            'action' => $log->action,
            'user_id' => $log->user_id,
            'user_name' => $log->user_name ?? 'System / visitor',
            'summary' => $log->summary,
            'description' => $log->description,
            'subject_type' => $log->subject_type,
            'subject_id' => $log->subject_id,
            'subject_label' => $log->subject_label,
            'properties' => $log->properties,
            'ip_address' => $log->ip_address,
            'user_agent' => $log->user_agent,
            'device' => $log->user_agent ? Audit::device($log->user_agent) : null,
        ];
    }
}
