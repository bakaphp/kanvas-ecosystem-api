<?php

declare(strict_types=1);

namespace Kanvas\Event\Participants\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Models\BaseModel;

class ParticipantPass extends BaseModel
{
    protected $table = 'participant_passes';
    protected $guarded = [];

    protected $is_deleted;

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
