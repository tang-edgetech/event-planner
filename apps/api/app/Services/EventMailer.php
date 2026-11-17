<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Event;
use App\Support\Audit;
use App\Support\FormSchema;
use App\Support\MailSettings;
use App\Support\Placeholders;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Registration emails: admin notification, applicant confirmation, waiting list, seat offer, capacity alert.
 * Every send (and every failure) is written to the audit log.
 */
class EventMailer
{
    public function registered(Applicant $applicant): void
    {
        $event = $applicant->event;
        $fields = FormSchema::forEvent($event);
        $data = $applicant->data ?? [];

        if ($event->notify_admin && ($to = $event->adminRecipients())) {
            $body = '<p>A new registration was received for <strong>'.e($event->title).'</strong>.</p>'
                .Placeholders::detailsTable($data, $fields)
                .'<p>Status: '.e(Applicant::STATUSES[$applicant->status] ?? $applicant->status).'</p>';
            $this->send($to, 'New registration: '.$event->title, $body, $applicant, 'admin notification');
        }

        if ($event->notify_applicant && $applicant->email) {
            // At most 5 automatic emails per address per hour, so the public form cannot flood an inbox.
            $key = 'mail-cap:'.sha1(strtolower($applicant->email));
            $sent = (int) Cache::get($key, 0) + 1;
            Cache::put($key, $sent, now()->addHour());
            if ($sent > 5) {
                return;
            }
            $this->confirmation($applicant, $applicant->status === 'waitlist' ? 'waitlist' : 'registered');
        }
    }

    public function confirmation(Applicant $applicant, string $kind = 'registered'): bool
    {
        if (! $applicant->email) {
            return false;
        }
        $event = $applicant->event;
        $defaults = [
            'registered' => ['Registration received: {event_title}', '<p>Dear {salutation} {fullname},</p><p>Thank you for registering for <strong>{event_title}</strong>.</p><p>{event_date} {event_time}<br>{event_venue}</p>{registration_details}'],
            'waitlist' => ['You are on the waiting list: {event_title}', '<p>Dear {salutation} {fullname},</p><p>Thank you for your interest in <strong>{event_title}</strong>. The event is currently full, so you have been placed on the waiting list. We will email you if a seat becomes available.</p>{registration_details}'],
            'offer' => ['A seat is now available: {event_title}', '<p>Dear {salutation} {fullname},</p><p>Good news: a seat has become available for <strong>{event_title}</strong> and your registration is now confirmed.</p><p>{event_date} {event_time}<br>{event_venue}</p>{registration_details}'],
        ];
        [$subject, $body] = $defaults[$kind] ?? $defaults['registered'];
        if ($kind === 'registered') {
            $subject = $event->email_subject ?: $subject;
            $body = $event->email_body ?: $body;
        }

        return $this->send(
            [$applicant->email],
            strip_tags(Placeholders::forApplicant($subject, $applicant)),
            Placeholders::forApplicant($body, $applicant),
            $applicant,
            $kind === 'offer' ? 'seat offer' : ($kind === 'waitlist' ? 'waiting list confirmation' : 'confirmation'),
        );
    }

    public function capacityAlert(Event $event, int $count, int $max, int $pct): void
    {
        if (! ($to = $event->adminRecipients())) {
            return;
        }
        $body = '<p><strong>'.e($event->title).'</strong> has reached '.$pct.'% of its capacity: '.$count.' of '.$max.' seats are taken.</p>';
        $this->send($to, 'Capacity alert: '.$event->title, $body, $event, 'capacity alert');
    }

    /**
     * @param  list<string>  $to
     */
    public function send(array $to, string $subject, string $html, mixed $subject_model, string $kind): bool
    {
        MailSettings::apply();
        $wrapped = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#2a1a20;max-width:640px">'.$html.'</div>';
        try {
            Mail::html($wrapped, function ($m) use ($to, $subject) {
                $m->to($to)->subject($subject);
            });
        } catch (Throwable $e) {
            Audit::error('email.failed', "The {$kind} email could not be sent", "The system tried to send the {$kind} email \"{$subject}\" to ".implode(', ', $to).', but the mail server refused it: "'.$e->getMessage().'". Check Settings → Email / SMTP.', $subject_model, ['to' => $to, 'error' => $e->getMessage()]);

            return false;
        }
        Audit::log('email.sent', "Sent the {$kind} email to ".implode(', ', $to), "The {$kind} email \"{$subject}\" was sent to ".implode(', ', $to).'.', 'success', $subject_model, ['to' => $to, 'mailer' => config('mail.default')]);

        return true;
    }
}
