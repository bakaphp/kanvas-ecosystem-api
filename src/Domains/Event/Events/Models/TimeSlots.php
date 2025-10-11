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
        'capacity' => 'integer',
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
        'capacity',
        'status',
        'price_snapshot',
        'currency',
        'meta',
    ];

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
     * Check if this time slot has an existing booking/event
     * Simply checks if there are any non-deleted event_versions linked to this slot
     */
    public function hasBooking(): bool
    {
        return $this->eventVersions()
            ->where('is_deleted', 0)
            ->exists();
    }
}
