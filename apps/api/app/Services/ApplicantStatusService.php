<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Event;
use App\Models\User;
use App\Support\Audit;

/**
 * Changes an applicant's status, logs it, and handles the waiting list when a seat frees up
 * (Settings → Extra → waiting list: manual, notify the admin, or move the next person up automatically).
 */
class ApplicantStatusService
{
    public function __construct(private EventMailer $mailer) {}

    public function change(Applicant $applicant, string $status, ?User $by, bool $audit = true): bool
    {
        $old = $applicant->status;
        if ($old === $status || ! array_key_exists($status, Applicant::STATUSES)) {
            return false;
        }
        $applicant->forceFill(['status' => $status])->save();
        $labels = Applicant::STATUSES;
        $applicant->log("Status changed from {$labels[$old]} to {$labels[$status]}", $by?->id);

        if ($audit) {
            Audit::log('applicant.status_changed', ($by?->name ?? 'The system')." changed {$applicant->name}'s status to {$labels[$status]}", ($by?->name ?? 'The system')." changed the status of {$applicant->name} for \"{$applicant->event?->title}\" from {$labels[$old]} to {$labels[$status]}.", 'success', $applicant, ['from' => $old, 'to' => $status], $by, $applicant->name ?: 'Applicant #'.$applicant->id);
        }

        $freedSeat = in_array($old, Event::SEATED, true) && ! in_array($status, Event::SEATED, true);
        if ($freedSeat && $applicant->event) {
            $this->seatFreed($applicant->event);
        }

        return true;
    }

    private function seatFreed(Event $event): void
    {
        $mode = setting('extra', 'waitlist_mode', 'manual');
        if (! $event->enable_waitlist || $mode === 'manual') {
            return;
        }
        $next = $event->applicants()->where('status', 'waitlist')->orderBy('id')->first();
        if (! $next) {
            return;
        }
        if ($mode === 'notify') {
            if ($to = $event->adminRecipients()) {
                $this->mailer->send($to, 'A seat is free: '.$event->title, '<p>A seat became free for <strong>'.e($event->title).'</strong>. The next person on the waiting list is <strong>'.e((string) $next->name).'</strong> ('.e((string) $next->email).').</p>', $event, 'seat-free notice');
            }

            return;
        }
        if ($event->capacity_max && $event->seatedCount() >= $event->capacity_max) {
            return;
        }
        $next->forceFill(['status' => 'confirmed'])->save();
        $next->log('Moved up from the waiting list automatically: a seat became free');
        Audit::log('applicant.promoted', "{$next->name} was moved up from the waiting list", "A seat became free for \"{$event->title}\", so {$next->name} (first on the waiting list) was confirmed automatically and emailed.", 'success', $next, [], null, $next->name ?: 'Applicant #'.$next->id);
        $this->mailer->confirmation($next, 'offer');
    }
}
