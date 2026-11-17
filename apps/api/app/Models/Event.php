<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Unguarded]
class Event extends Model
{
    use SoftDeletes;

    /** Applicant statuses that take a seat. */
    public const SEATED = ['registered', 'confirmed', 'attended'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'reg_open_at' => 'datetime',
            'reg_close_at' => 'datetime',
            'capacity_alert_sent_at' => 'datetime',
            'form_fields' => 'array',
            'show_title' => 'boolean',
            'enable_waitlist' => 'boolean',
            'notify_admin' => 'boolean',
            'notify_applicant' => 'boolean',
            'capacity_min' => 'integer',
            'capacity_max' => 'integer',
        ];
    }

    /**
     * @return HasMany<Applicant, $this>
     */
    public function applicants(): HasMany
    {
        return $this->hasMany(Applicant::class);
    }

    public function seatedCount(): int
    {
        return $this->applicants()->whereIn('status', self::SEATED)->count();
    }

    /**
     * Registration state.
     *
     * @return array{state: string, label: string, count: int, max: ?int, remaining: ?int}
     */
    public function registrationState(): array
    {
        $count = $this->seatedCount();
        $max = $this->capacity_max ?: null;
        $state = 'open';

        if ($this->status !== 'published') {
            $state = 'closed';
        } elseif ($this->reg_status === 'open') {
            $state = 'open';
        } elseif ($this->reg_status === 'closed') {
            $state = 'closed';
        } elseif ($this->reg_status === 'full') {
            $state = 'full';
        } elseif ($this->reg_open_at && now()->lt($this->reg_open_at)) {
            $state = 'not_open';
        } elseif ($this->reg_close_at && now()->gt($this->reg_close_at)) {
            $state = 'closed';
        }

        if ($state === 'open' && $max !== null && $count >= $max) {
            $state = $this->enable_waitlist ? 'waitlist' : 'full';
        }

        $labels = [
            'open' => 'Registration open',
            'waitlist' => 'Waiting list open',
            'full' => 'Application full',
            'closed' => 'Registration closed',
            'not_open' => 'Opening soon',
        ];

        return [
            'state' => $state,
            'label' => $labels[$state],
            'count' => $count,
            'max' => $max,
            'remaining' => $max !== null ? max(0, $max - $count) : null,
        ];
    }

    public function isAccepting(): bool
    {
        return in_array($this->registrationState()['state'], ['open', 'waitlist'], true);
    }

    /**
     * Admin email recipients for this event (falls back to the Email settings).
     *
     * @return list<string>
     */
    public function adminRecipients(): array
    {
        $list = preg_split('/[\s,;]+/', (string) $this->admin_emails) ?: [];
        $list = array_values(array_filter($list, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));

        if (! $list) {
            $fallback = preg_split('/[\s,;]+/', (string) setting('email', 'admin_recipients', '')) ?: [];
            $list = array_values(array_filter($fallback, fn ($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        }

        return $list;
    }
}
