<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Unguarded]
class Applicant extends Model
{
    use SoftDeletes;

    /** @var array<string, string> */
    public const STATUSES = [
        'registered' => 'Registered',
        'confirmed' => 'Confirmed',
        'waitlist' => 'Waiting list',
        'attended' => 'Attended',
        'cancelled' => 'Cancelled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'data' => 'array',
            'labels' => 'array',
            'source' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    /**
     * @return HasMany<ApplicantActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(ApplicantActivity::class)->latest('id');
    }

    public function log(string $message, ?int $userId = null): void
    {
        $this->activities()->create(['message' => $message, 'user_id' => $userId, 'created_at' => now()]);
    }
}
