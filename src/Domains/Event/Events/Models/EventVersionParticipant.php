<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Models;

use Baka\Casts\Json;
use Baka\Traits\NoAppRelationshipTrait;
use Baka\Traits\NoCompanyRelationshipTrait;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kanvas\Event\Events\Observers\EventVersionParticipantObserver;
use Kanvas\Event\Models\BaseModel;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Workflow\Traits\CanUseWorkflow;

#[ObservedBy([EventVersionParticipantObserver::class])]
class EventVersionParticipant extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use CanUseWorkflow;

    protected $table = 'event_version_participants';
    protected $guarded = [];

    protected $is_deleted;

    public function eventVersion(): BelongsTo
    {
        return $this->belongsTo(EventVersion::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(Participant::class);
    }

    public function participantType(): BelongsTo
    {
        return $this->belongsTo(ParticipantType::class);
    }

    protected function casts(): array
    {
        return [
            'metadata' => Json::class,
        ];
    }
}
