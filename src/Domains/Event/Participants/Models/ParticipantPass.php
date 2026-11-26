<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Passes\Enums\InviteCodeStatusEnum;

class ParticipantPass extends BaseModel
{
    protected $table = 'participant_passes';
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'redeemed_at' => 'datetime',
            'event_wide' => 'boolean',
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
            ->whereDoesntHave('event.eventStatus', fn (Builder $status) => $status->cancelled());
    }

    public function scopeActiveBookingPass(Builder $query): Builder
    {
        return $query
            ->whereNull('participant_id')
            ->whereNull('revoked_at')
            ->notDeleted()
            ->orderBy('id');
    }

    public function liveBookingIds(bool $locking = false): Collection
    {
        $redemptions = $locking ? $this->redemptions()->lockForUpdate() : $this->redemptions();
        $ids = $redemptions->pluck('booking_event_version_id');

        if ($this->redeemed_event_version_id !== null) {
            $ids->push($this->redeemed_event_version_id);
        }

        $bookings = EventVersion::query()->live()->whereIn('id', $ids->unique()->all());

        return ($locking ? $bookings->lockForUpdate() : $bookings)->pluck('id');
    }

    public function liveUseCount(bool $locking = false): int
    {
        if (! $locking && $this->getAttribute('live_uses') !== null) {
            return (int) $this->getAttribute('live_uses');
        }

        return $this->liveBookingIds($locking)->count();
    }

    public function hasLiveRedemption(): bool
    {
        return $this->liveUseCount() > 0;
    }

    public function remainingUses(bool $locking = false): int
    {
        return max(0, (int) ($this->max_uses ?? 1) - $this->liveUseCount($locking));
    }

    public function recordRedemption(EventVersion $booking): void
    {
        ParticipantPassRedemption::query()->firstOrCreate([
            'participant_passes_id' => $this->getId(),
            'booking_event_version_id' => $booking->getId(),
        ]);
    }

    public function bookedDays(bool $locking = false): array
    {
        $bookings = EventVersion::query()
            ->whereIn('id', $this->liveBookingIds($locking))
            ->with('timeSlot.resource');

        return ($locking ? $bookings->lockForUpdate() : $bookings)
            ->get()
            ->map(fn (EventVersion $booking) => $booking->timeSlot?->localDate())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    public function allowedDays(): array
    {
        $days = $this->event_wide
            ? EventVersion::query()->daysOfEvent((int) $this->event_id)->get()
            : collect([$this->eventVersion]);

        return $days
            ->filter()
            ->map(fn (EventVersion $version) => $version->start_at->toDateString())
            ->unique()
            ->sort()
            ->values()
            ->all();
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

    public function isValid(): bool
    {
        return $this->revoked_at === null && ! now()->gt(Carbon::parse($this->expiration_date)->endOfDay());
    }

    public function isRedeemable(): bool
    {
        return $this->isValid() && $this->remainingUses() > 0;
    }

    public function plainCode(): string
    {
        return decrypt($this->code);
    }

    public function inviteStatus(): string
    {
        return match (true) {
            $this->revoked_at !== null => InviteCodeStatusEnum::REVOKED->value,
            $this->hasLiveRedemption() => InviteCodeStatusEnum::REDEEMED->value,
            default => InviteCodeStatusEnum::AVAILABLE->value,
        };
    }

    public function scopeWithInviteStatus(Builder $query, ?InviteCodeStatusEnum $status): Builder
    {
        return match ($status) {
            null => $query,
            InviteCodeStatusEnum::REVOKED => $query->whereNotNull('revoked_at'),
            InviteCodeStatusEnum::REDEEMED => $query
                ->whereNull('revoked_at')
                ->where(
                    fn (Builder $live) => $live
                        ->whereHas('redemptionHolder', fn (Builder $holder) => $holder->live())
                        ->orWhereHas('redeemedBookings', fn (Builder $booking) => $booking->live())
                ),
            InviteCodeStatusEnum::AVAILABLE => $query
                ->whereNull('revoked_at')
                ->whereDoesntHave('redemptionHolder', fn (Builder $holder) => $holder->live())
                ->whereDoesntHave('redeemedBookings', fn (Builder $booking) => $booking->live()),
        };
    }

    public function scopeRedeemedByBooking(Builder $query, int $bookingId): Builder
    {
        return $query->where(
            fn (Builder $redeemed) => $redeemed
                ->where('redeemed_event_version_id', $bookingId)
                ->orWhereHas('redeemedBookings', fn (Builder $booking) => $booking->whereKey($bookingId))
        );
    }

    public function isRedeemedBy(EventVersion $booking): bool
    {
        return $this->redeemed_at !== null && (
            (int) $this->redeemed_event_version_id === $booking->getId()
            || $this->redemptions()->where('booking_event_version_id', $booking->getId())->exists()
        );
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(ParticipantPassRedemption::class, 'participant_passes_id');
    }

    public function redeemedBookings(): BelongsToMany
    {
        return $this->belongsToMany(
            EventVersion::class,
            'participant_pass_redemptions',
            'participant_passes_id',
            'booking_event_version_id'
        );
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
