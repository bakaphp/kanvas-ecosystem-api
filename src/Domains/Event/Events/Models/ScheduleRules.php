<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Event\Models\BaseModel;

class ScheduleRules extends BaseModel
{
    use UuidTrait;

    protected $table = 'schedule_rules';
    protected $guarded = [];

    protected $casts = [
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'is_deleted' => 'boolean',
        'slot_duration_min' => 'integer',
        'lead_time_min' => 'integer',
        'cutoff_time_min' => 'integer',
        'capacity_override' => 'integer',
        'metadata' => 'array',
    ];

    protected $fillable = [
        'apps_id',
        'companies_id',
        'uuid',
        'resources_id',
        'resources_type',
        'start_at',
        'end_at',
        'rrule',
        'day_rrule',
        'slot_duration_min',
        'lead_time_min',
        'cutoff_time_min',
        'capacity_override',
        'metadata',
        'is_deleted',
    ];

    public function resource(): MorphTo
    {
        return $this->morphTo('resources');
    }

    public function timeSlots(): HasMany
    {
        return $this->hasMany(TimeSlots::class, 'schedule_rules_id');
    }

    /**
     * Delete all upcoming time slots for this schedule rule
     * Uses a simple query to permanently remove unbooked slots from the database
     * Skips time slots that have existing bookings to protect customer reservations
     */
    public function deleteUpcomingTimeSlots(): void
    {
        // Get IDs of time slots that have bookings using the time_slot_id foreign key
        $bookedSlotIds = DB::connection('event')->table('event_versions')
            ->select('time_slot_id')
            ->whereNotNull('time_slot_id')
            ->where('is_deleted', 0)
            ->whereIn('time_slot_id', function ($query) {
                $query->select('id')
                    ->from('time_slots')
                    ->where('schedule_rules_id', $this->id)
                    ->where('start_at', '>=', Carbon::now());
            })
            ->pluck('time_slot_id');

        $this->timeSlots()
            ->where('start_at', '>=', Carbon::now())
            ->whereNotIn('id', $bookedSlotIds)
            ->forceDelete();
    }
}
