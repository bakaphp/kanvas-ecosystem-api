<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Workflow\Enums\WorkflowEnum;

class EventVersionParticipantObserver
{
    public function created(EventVersionParticipant $eventVersionParticipant): void
    {
        $eventVersionParticipant->eventVersion->incrementAttendees();
        $this->fireEventVersionWorkflow($eventVersionParticipant, 'participant-added');
    }

    public function deleted(EventVersionParticipant $eventVersionParticipant): void
    {
        $eventVersionParticipant->eventVersion->decrementAttendees();
        $this->fireEventVersionWorkflow($eventVersionParticipant, 'participant-removed');
    }

    public function updated(EventVersionParticipant $eventVersionParticipant): void
    {
        if (! $eventVersionParticipant->wasChanged('is_deleted')) {
            return;
        }

        $eventVersionParticipant->isDeleted()
            ? $eventVersionParticipant->eventVersion->decrementAttendees()
            : $eventVersionParticipant->eventVersion->incrementAttendees();

        $this->fireEventVersionWorkflow(
            $eventVersionParticipant,
            $eventVersionParticipant->isDeleted() ? 'participant-removed' : 'participant-added',
        );
    }

    protected function fireEventVersionWorkflow(
        EventVersionParticipant $eventVersionParticipant,
        string $change,
    ): void {
        $eventVersion = $eventVersionParticipant->eventVersion;
        $eventVersion->fireWorkflow(
            WorkflowEnum::EVENT_VERSIONS_WORKFLOW->value,
            true,
            [
                'app' => $eventVersion->app,
                'company' => $eventVersion->company,
                'event_version_change' => $change,
                'event_version_participant_id' => $eventVersionParticipant->getId(),
            ],
        );
    }
}
