<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Kanvas\Event\Models\BaseModel;

class TimeSlots extends BaseModel
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

    protected $appends = ['capacity'];

    public function resource(): MorphTo
    {
        return $this->morphTo('resources');
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
        return (int) $this->eventVersions()
            ->where('is_deleted', 0)
            ->sum('total_attendees');
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
}
