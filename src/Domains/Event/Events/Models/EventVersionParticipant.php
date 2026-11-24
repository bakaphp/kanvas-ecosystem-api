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
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Traits\CanUseWorkflow;
use Override;

#[ObservedBy([EventVersionParticipantObserver::class])]
class EventVersionParticipant extends BaseModel
{
    use NoAppRelationshipTrait;
    use NoCompanyRelationshipTrait;
    use CanUseWorkflow;

    protected $table = 'event_version_participants';
    protected $guarded = [];

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

    /**
     * Fired on the EventVersion, not on this row: rules are configured per EventVersion and read
     * `event_version_change` (participant-added / -removed / -updated) to tell the cases apart.
     */
    public function fireEventVersionWorkflow(string $change): void
    {
        $eventVersion = $this->eventVersion;
        $eventVersion->fireWorkflow(
            WorkflowEnum::EVENT_VERSIONS_WORKFLOW->value,
            true,
            [
                'app' => $eventVersion->app,
                'company' => $eventVersion->company,
                'event_version_change' => $change,
                'event_version_participant_id' => $this->getId(),
            ],
        );
    }

    #[Override]
    protected function casts(): array
    {
        return [
            'metadata' => Json::class,
        ];
    }
}
