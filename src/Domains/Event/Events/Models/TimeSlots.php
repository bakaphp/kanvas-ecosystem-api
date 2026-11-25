<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Companies\Contracts\ReadableAtLocation;
use Kanvas\Event\Events\Enums\TimeSlotStatusEnum;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class TimeSlots extends BaseModel implements ReadableAtLocation
{
    protected $table = 'time_slots';
    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'initial_capacity' => 'integer',
        'price_snapshot_cents' => 'integer',
        'meta' => 'array',
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'resources_id',
        'resources_type',
        'schedule_rules_id',
        'start_at',
        'end_at',
        'initial_capacity',
        'status',
        'price_snapshot',
        'currency',
        'meta',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo('resource', 'resources_type', 'resources_id');
    }

    public function scheduleRule(): BelongsTo
    {
        return $this->belongsTo(ScheduleRules::class, 'schedule_rules_id');
    }

    public function eventVersions(): HasMany
    {
        return $this->hasMany(EventVersion::class, 'time_slot_id');
    }

    public function isFromScheduleRule(): bool
    {
        return $this->schedule_rules_id !== null;
    }

    public function isStandalone(): bool
    {
        return $this->schedule_rules_id === null;
    }

    /**
     * A slot stops being bookable the moment it starts. Booking a started slot used to be
     * allowed and only blew up later in CreatePassAction — after the customer had paid.
     */
    public function hasStarted(): bool
    {
        return $this->start_at !== null && $this->start_at->isPast();
    }

    public function localDate(): string
    {
        return $this->start_at
            ->copy()
            ->setTimezone(ResourceTimezoneService::resolve($this->resource))
            ->toDateString();
    }

    public function scopeForProduct(Builder $query, Products $product): Builder
    {
        return $query->where(
            fn (Builder $resource) => $resource
                ->where(
                    fn (Builder $own) => $own
                        ->where('resources_type', $product->getMorphClass())
                        ->where('resources_id', $product->getId())
                )
                ->orWhere(
                    fn (Builder $variant) => $variant
                        ->where('resources_type', new Variants()->getMorphClass())
                        ->whereIn('resources_id', $product->variants()->pluck('id'))
                )
        );
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('start_at', '>', now());
    }

    /**
     * Capacity accessor - returns available slots (for backward compatibility with frontend)
     * Frontend expects 'capacity' to show available slots
     */
    public function getCapacityAttribute(): int
    {
        return $this->getAvailableSlots();
    }

    /**
     * Check if this time slot has an existing booking/event
     * Simply checks if there are any non-deleted event_versions linked to this slot
     */
    public function hasBooking(): bool
    {
        return $this->eventVersions()
            ->where('is_deleted', 0)
            ->exists();
    }

    /**
     * Get the count of booked slots for this time slot
     * Sums total_attendees from all active event versions associated with this time slot
     */
    public function getBookedSlotsCount(): int
    {
        return (int) $this->eventVersions()->live()->sum('total_attendees');
    }

    /**
     * Get available slots remaining
     * Calculates: initial_capacity - booked events count
     */
    public function getAvailableSlots(): int
    {
        $bookedCount = $this->getBookedSlotsCount();

        return max(0, $this->initial_capacity - $bookedCount);
    }

    /**
     * Check if the time slot has enough available capacity for booking
     */
    public function hasAvailableCapacity(int $requiredSlots = 1): bool
    {
        return $this->getAvailableSlots() >= $requiredSlots;
    }

    /**
     * Check if the time slot is fully booked
     */
    public function isFullyBooked(): bool
    {
        return $this->getAvailableSlots() <= 0;
    }

    public function autoUpdateStatus(): void
    {
        $newStatus = $this->isFullyBooked()
            ? TimeSlotStatusEnum::BOOKED->value
            : TimeSlotStatusEnum::OPEN->value;

        if ($this->status !== $newStatus) {
            $this->update(['status' => $newStatus]);
        }
    }
}
