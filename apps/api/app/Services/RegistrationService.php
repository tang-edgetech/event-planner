<?php

namespace App\Services;

use App\Models\Applicant;
use App\Models\Event;
use App\Support\Audit;
use App\Support\FieldValidator;
use App\Support\FormSchema;
use App\Support\Placeholders;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Validates and stores a public registration.
 *
 * Order: gate checks (published, spam token, honeypot, rate limit, capacity) → clean and validate every field
 * → inside a database lock: re-check unique fields and capacity, then save → emails, capacity alert, audit log.
 */
class RegistrationService
{
    public const OTHER = '__other';

    /** Successful registrations allowed per visitor IP, per event, per hour. */
    public const RATE_LIMIT = 20;

    public function __construct(private EventMailer $mailer) {}

    public static function token(Event $event, ?int $time = null): string
    {
        $time ??= time();

        return $time.'.'.self::sign($event, $time);
    }

    private static function sign(Event $event, int $time): string
    {
        return substr(hash_hmac('sha256', $event->id.'|'.$time, (string) config('app.key')), 0, 24);
    }

    private static function tokenAge(Event $event, mixed $token): ?int
    {
        $parts = explode('.', is_string($token) ? $token : '', 2);
        if (count($parts) !== 2 || ! ctype_digit($parts[0]) || ! hash_equals(self::sign($event, (int) $parts[0]), $parts[1])) {
            return null;
        }

        return time() - (int) $parts[0];
    }

    /**
     * @param  array<string, mixed>  $post
     * @param  array{ip?: string, visitor?: string, source?: array<string, mixed>}  $context
     * @return array{ok: bool, message: string, errors: array<string, string>, redirect: string, html: string}
     */
    public function process(Event $event, array $post, array $context = []): array
    {
        $fail = fn (string $message, array $errors = []) => ['ok' => false, 'message' => $message, 'errors' => $errors, 'redirect' => '', 'html' => ''];
        $ip = $context['ip'] ?? '0.0.0.0';

        $age = self::tokenAge($event, $post['_token_ts'] ?? '');
        if (! empty($post['_hp']) || $age === null || $age < 3 || $age > 86400) {
            Audit::error('registration.blocked', "A suspicious registration for \"{$event->title}\" was blocked", 'A registration was sent without a valid form token, too fast after the page loaded, or with the hidden trap field filled in. This is typical of automated spam, so it was rejected and nothing was saved.', $event, ['ip' => $ip, 'age' => $age]);

            return $fail('Submission rejected. Please reload the page and try again.');
        }

        $rateKey = 'reg-rate:'.sha1($ip.'|'.$event->id);
        if ((int) Cache::get($rateKey, 0) >= self::RATE_LIMIT) {
            Audit::error('registration.rate_limited', "Too many registrations for \"{$event->title}\" from one network", 'More than '.self::RATE_LIMIT." registrations for this event came from the same internet address ({$ip}) within an hour, so further ones are refused for now.", $event, ['ip' => $ip]);

            return $fail('Too many registrations have been sent from your network. Please try again in an hour.');
        }

        if (! $event->isAccepting()) {
            return $fail($this->closedText($event));
        }

        $fields = FormSchema::dataFields($event);
        [$data, $errors] = $this->validate($event, $fields, $post);
        if ($errors) {
            return $fail('Please correct the highlighted fields.', $errors);
        }

        $result = DB::transaction(function () use ($event, $fields, $data, $context, $ip, $fail) {
            $locked = Event::query()->lockForUpdate()->find($event->id);
            foreach ($fields as $f) {
                $value = $data[$f['name']] ?? '';
                if ($f['unique'] && is_string($value) && $value !== '' && $this->exists($event, $f['name'], $value)) {
                    return $fail('Please correct the highlighted fields.', [$f['name'] => $this->duplicateMessage($f)]);
                }
            }
            $state = $locked->registrationState();
            if (! in_array($state['state'], ['open', 'waitlist'], true)) {
                return $fail($this->closedText($locked));
            }
            $status = $state['state'] === 'waitlist' ? 'waitlist' : ($locked->default_status ?: 'registered');

            $applicant = Applicant::query()->create([
                'event_id' => $locked->id,
                'status' => $status,
                'name' => $this->applicantName($data),
                'email' => $this->firstOfType($fields, $data, 'email'),
                'phone' => $this->firstOfType($fields, $data, 'phone'),
                'data' => $data,
                'labels' => collect($fields)->mapWithKeys(fn ($f) => [$f['name'] => strip_tags((string) $f['label']) ?: $f['name']])->all(),
                'source' => $context['source'] ?? null,
                'visitor_id' => isset($context['visitor']) ? substr(preg_replace('/[^a-zA-Z0-9-]/', '', (string) $context['visitor']), 0, 64) : null,
                'ip_hash' => hash_hmac('sha256', $ip, (string) config('app.key')),
            ]);
            $applicant->log('Registered through the public form'.($status === 'waitlist' ? ' (placed on the waiting list)' : ''));

            return $applicant;
        });

        if (is_array($result)) {
            return $result;
        }
        /** @var Applicant $applicant */
        $applicant = $result;
        Cache::put($rateKey, (int) Cache::get($rateKey, 0) + 1, now()->addHour());

        Audit::log('registration.created', "New registration for \"{$event->title}\": {$applicant->name}", ($applicant->name ?: 'Someone')." registered for \"{$event->title}\" through the public form".($applicant->status === 'waitlist' ? ' and was placed on the waiting list because the event is full.' : '.'), 'success', $applicant, ['status' => $applicant->status, 'source' => $applicant->source], null, $applicant->name ?: 'Applicant #'.$applicant->id);

        $this->mailer->registered($applicant);
        $this->capacityAlert($event);

        $message = $event->confirmation_message ?: '<h3>Thank you!</h3><p>Your registration for {event_title} has been received.</p>';
        $redirect = $event->confirmation_type === 'redirect' ? (string) $event->redirect_url : '';
        if ($applicant->status === 'waitlist') {
            $message = $event->waitlist_confirmation ?: '<h3>You are on the waiting list</h3><p>We will contact you if a seat becomes available.</p>';
            $redirect = '';
        }

        return [
            'ok' => true,
            'message' => 'Registered',
            'errors' => [],
            'redirect' => filter_var($redirect, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $redirect) ? $redirect : '',
            'html' => Placeholders::replace($message, $event, $data, $fields),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $post
     * @return array{0: array<string, mixed>, 1: array<string, string>}
     */
    public function validate(Event $event, array $fields, array $post): array
    {
        $raw = [];
        foreach ($fields as $f) {
            $v = $post[$f['name']] ?? '';
            if ($f['type'] === 'checkboxes') {
                $v = array_values(array_map(fn ($x) => trim(strip_tags((string) $x)), array_filter((array) $v, 'is_scalar')));
            } elseif (! is_scalar($v)) {
                $v = '';
            } else {
                $v = $f['type'] === 'textarea' ? trim(strip_tags((string) $v)) : trim(preg_replace('/\s+/', ' ', strip_tags((string) $v)) ?? '');
            }
            $raw[$f['name']] = $v;
        }

        $data = [];
        $errors = [];
        foreach ($fields as $f) {
            $name = $f['name'];
            if (! self::isVisible($f, $raw)) {
                continue;
            }
            $v = $raw[$name];
            if ($f['type'] === 'hidden') {
                $data[$name] = $f['default'];

                continue;
            }
            $empty = is_array($v) ? ! array_filter($v, 'strlen') : $v === '';
            if ($empty) {
                if ($f['required']) {
                    $errors[$name] = $f['type'] === 'consent' ? 'You must agree to continue.' : (strip_tags($f['label']) ?: 'This field').' is required.';
                }
                $data[$name] = $f['type'] === 'checkboxes' ? [] : '';

                continue;
            }

            $other = $post[$name.'__other'] ?? '';
            $other = is_scalar($other) ? trim(strip_tags((string) $other)) : '';
            if ($f['allow_other']) {
                if (is_array($v) && in_array(self::OTHER, $v, true)) {
                    $v = array_map(fn ($x) => $x === self::OTHER ? 'Others: '.$other : $x, $v);
                    if ($other === '') {
                        $errors[$name] = 'Please specify "Others".';
                    }
                } elseif ($v === self::OTHER) {
                    if ($other === '') {
                        $errors[$name] = 'Please specify "Others".';
                    }
                    $v = 'Others: '.$other;
                }
            }

            [$ok, $v, $msg] = match ($f['type']) {
                'email' => FieldValidator::email($v),
                'phone' => FieldValidator::phone($v, $f['format'] ?: 'my'),
                'nric' => FieldValidator::nric($v, $f['format'] ?: 'my'),
                'number' => FieldValidator::number($v),
                'date' => FieldValidator::date($v),
                'select', 'radio', 'salutation', 'country' => [in_array($v, $f['options'], true) || ($f['allow_other'] && str_starts_with($v, 'Others: ')), $v, 'Please choose a valid option.'],
                'checkboxes' => [! array_diff(array_filter($v, fn ($x) => ! str_starts_with($x, 'Others: ')), $f['options']), $v, 'Please choose valid options.'],
                'consent' => [true, 'Agreed', ''],
                default => [mb_strlen((string) $v) <= 5000, $v, 'This answer is too long.'],
            };
            if (! $ok && ! isset($errors[$name])) {
                $errors[$name] = $msg;
            }
            if ($ok && $f['unique'] && is_string($v) && $this->exists($event, $name, $v)) {
                $errors[$name] = $this->duplicateMessage($f);
            }
            $data[$name] = $v;
        }

        return [$data, $errors];
    }

    /**
     * @param  array<string, mixed>  $f
     * @param  array<string, mixed>  $values
     */
    public static function isVisible(array $f, array $values): bool
    {
        $c = $f['condition'] ?? null;
        if (! $c || empty($c['field'])) {
            return true;
        }
        $actual = $values[$c['field']] ?? '';
        $list = is_array($actual) ? $actual : [$actual];
        $target = strtolower(trim((string) $c['value']));
        $hit = in_array($target, array_map(fn ($x) => strtolower(trim((string) $x)), $list), true);

        return match ($c['operator']) {
            'is_not' => ! $hit,
            'not_empty' => (bool) array_filter($list, 'strlen'),
            'empty' => ! array_filter($list, 'strlen'),
            'contains' => $target !== '' && str_contains(strtolower(implode(' ', $list)), $target),
            default => $hit,
        };
    }

    public function exists(Event $event, string $name, string $value): bool
    {
        return Applicant::query()
            ->where('event_id', $event->id)
            ->where('status', '!=', 'cancelled')
            ->where('data->'.$name, $value)
            ->exists();
    }

    public function closedText(Event $event): string
    {
        $state = $event->registrationState()['state'];

        return match ($state) {
            'full' => trim(strip_tags(($event->full_title ?: 'Application Full').'. '.($event->full_message ?: 'We have reached the maximum registration capacity.'))),
            'not_open' => 'Registration opens on '.($event->reg_open_at?->format('j F Y, g:i A') ?? 'a later date').'.',
            default => trim(strip_tags(($event->closed_title ?: 'Registration Closed').'. '.($event->closed_message ?: 'Registration for this event is now closed.'))),
        };
    }

    private function capacityAlert(Event $event): void
    {
        if (! setting('extra', 'capacity_alert', false) || ! $event->capacity_max || $event->capacity_alert_sent_at) {
            return;
        }
        $pct = (int) setting('extra', 'capacity_alert_pct', 90);
        $count = $event->seatedCount();
        if ($count * 100 >= $event->capacity_max * $pct) {
            $event->forceFill(['capacity_alert_sent_at' => now()])->save();
            $this->mailer->capacityAlert($event, $count, $event->capacity_max, $pct);
        }
    }

    /**
     * @param  array<string, mixed>  $f
     */
    private function duplicateMessage(array $f): string
    {
        return 'This '.(strtolower(strip_tags((string) $f['label'])) ?: 'value').' has already been registered for this event.';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function applicantName(array $data): string
    {
        $parts = [];
        foreach (['salutation', 'full_name', 'fullname', 'name', 'first_name', 'last_name'] as $k) {
            if (! empty($data[$k]) && is_string($data[$k])) {
                $parts[] = $data[$k];
            }
        }
        if (count($parts) <= 1) {
            foreach ($data as $k => $v) {
                if (is_string($v) && str_contains($k, 'name') && ! in_array($v, $parts, true)) {
                    $parts[] = $v;
                    break;
                }
            }
        }

        return mb_substr(trim((string) preg_replace('/\bOthers: /', '', implode(' ', $parts))), 0, 190);
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @param  array<string, mixed>  $data
     */
    private function firstOfType(array $fields, array $data, string $type): ?string
    {
        foreach ($fields as $f) {
            if ($f['type'] === $type && ! empty($data[$f['name']]) && is_string($data[$f['name']])) {
                return mb_substr($data[$f['name']], 0, $type === 'phone' ? 40 : 190);
            }
        }

        return null;
    }
}
