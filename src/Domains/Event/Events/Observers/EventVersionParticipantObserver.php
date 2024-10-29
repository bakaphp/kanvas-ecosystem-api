<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Observers;

use Kanvas\Event\Events\Models\EventVersionParticipant;

class EventVersionParticipantObserver
{
    public function created(EventVersionParticipant $eventVersionParticipant): void
    {
        $eventVersionParticipant->eventVersion->incrementAttendees();
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
    }
}
