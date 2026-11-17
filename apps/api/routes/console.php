<?php

use App\Models\AuditLog;
use App\Support\Audit;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('audit:prune', function () {
    $days = max(30, (int) setting('logs', 'retention_days', 365));
    $deleted = AuditLog::query()->where('created_at', '<', now()->subDays($days))->delete();
    if ($deleted) {
        Audit::log('system.audit_pruned', "{$deleted} old audit log entries were removed", "Audit log entries older than {$days} days (Settings → Logs → keep logs for) were deleted automatically. {$deleted} entries were removed.", 'success', null, ['days' => $days, 'deleted' => $deleted]);
    }
    $this->info("Removed {$deleted} entries older than {$days} days.");
})->purpose('Delete audit log entries older than the retention period in Settings → Logs');

Schedule::command('audit:prune')->dailyAt('03:00');
