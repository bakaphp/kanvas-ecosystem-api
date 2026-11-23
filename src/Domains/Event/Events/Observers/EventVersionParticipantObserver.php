<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Kanvas\Event\Events\Models\EventVersionParticipant;

class EventVersionParticipantObserver
{
    public function created(EventVersionParticipant $eventVersionParticipant): void
    {
        $eventVersionParticipant->eventVersion->incrementAttendees();
        $eventVersionParticipant->fireEventVersionWorkflow('participant-added');
    }

    public function deleted(EventVersionParticipant $eventVersionParticipant): void
    {
        $eventVersionParticipant->eventVersion->decrementAttendees();
        $eventVersionParticipant->fireEventVersionWorkflow('participant-removed');
    }

    public function updated(EventVersionParticipant $eventVersionParticipant): void
    {
        if (! $eventVersionParticipant->wasChanged('is_deleted')) {
            return;
        }

        $eventVersionParticipant->isDeleted()
            ? $eventVersionParticipant->eventVersion->decrementAttendees()
            : $eventVersionParticipant->eventVersion->incrementAttendees();

        $eventVersionParticipant->fireEventVersionWorkflow(
            $eventVersionParticipant->isDeleted() ? 'participant-removed' : 'participant-added',
        );
    }
}
