<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Participants\Models\Participant;

class EventVersionParticipantDate extends BaseModel
{
    protected $table = 'event_version_date_participants';
    protected $guarded = [];

    protected $is_deleted;

    public function eventVersionDate(): BelongsTo
    {
        return $this->belongsTo(EventVersionDate::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }
}
