<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Models\BaseModel;

class ParticipantPass extends BaseModel
{
    protected $table = 'participant_passes';
    protected $guarded = [];

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
