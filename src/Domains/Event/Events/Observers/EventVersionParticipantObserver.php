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
        $eventVersionParticipant->fireWorkflow(
            WorkflowEnum::CREATED->value,
            true,
        );
    }

    public function deleted(EventVersionParticipant $eventVersionParticipant): void
    {
        $eventVersionParticipant->eventVersion->decrementAttendees();
    }

    public function updated(EventVersionParticipant $eventVersionParticipant): void
    {
        if (! $eventVersionParticipant->isDeleted()) {
            $eventVersionParticipant->eventVersion->incrementAttendees();
        } else {
            $eventVersionParticipant->eventVersion->decrementAttendees();
        }

        $eventVersionParticipant->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            true,
        );
    }
}
