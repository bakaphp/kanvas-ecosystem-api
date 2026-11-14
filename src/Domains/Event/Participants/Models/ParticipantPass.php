<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Models\BaseModel;

class ParticipantPass extends BaseModel
{
    protected $table = 'participant_passes';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
        ];
    }

    /**
     * Passes of people who never checked in for something that already started.
     *
     * Grained on the pass rather than the booking on purpose: one pass is issued per participant,
     * but a single scan flips the whole Event to validated, so a group where some showed and some
     * did not still reads as attended at the booking level.
     *
     * Event-level passes (null participant) are excluded — they represent the booking, not a person,
     * and counting them adds a phantom absentee to every group.
     */
    public function scopeNoShow(Builder $query, bool $apply = true): Builder
    {
        if (! $apply) {
            return $query;
        }

        return $query
            ->whereNull('used_date')
            ->whereNotNull('participant_id')
            ->whereHas(
                'eventVersion',
                fn (Builder $version) => $version->where('is_deleted', 0)->where('start_at', '<', now())
            )
            // Nobody was expected to show up for a cancelled booking. Compared on slug *or* name
            // because seeded statuses carry a null slug while the ones written at runtime have one.
            ->whereDoesntHave(
                'event.eventStatus',
                fn (Builder $status) => $status->whereRaw(
                    'LOWER(COALESCE(NULLIF(slug, ""), name)) = ?',
                    [strtolower(EventStatusEnum::CANCELLED->value)]
                )
            );
    }

    private function redemptionHolderIsActive(): bool
    {
        if ($this->redeemed_event_version_id === null) {
            return false;
        }

        return EventVersion::query()
            ->whereKey($this->redeemed_event_version_id)
            ->where('is_deleted', 0)
            ->notCancelled()
            ->exists();
    }

    public function markAsUsed(): bool
    {
        $usedAt = now();

        $claimed = static::query()
            ->whereKey($this->getKey())
            ->whereNull('used_date')
            ->update(['used_date' => $usedAt]) === 1;

        if ($claimed) {
            $this->used_date = $usedAt;
            $this->syncOriginal();
        }

        return $claimed;
    }

    public function isRedeemable(): bool
    {
        if ($this->revoked_at !== null || now()->gt(Carbon::parse($this->expiration_date)->endOfDay())) {
            return false;
        }

        return $this->redeemed_at === null || ! $this->redemptionHolderIsActive();
    }

    public function isRedeemedBy(EventVersion $booking): bool
    {
        return $this->redeemed_at !== null && (int) $this->redeemed_event_version_id === $booking->getId();
    }

    public function redemptionHolder(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class, 'redeemed_event_version_id');
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function eventVersion(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class);
    }

    public function motive(): BelongsTo
    {
        return $this->belongsTo(ParticipantPassMotive::class);
    }
}
