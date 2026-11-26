<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\Actions;

use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Event\Events\Services\TimeSlotBookingLock;
use Kanvas\Exceptions\ValidationException;

class UpdateTimeSlotCapacityAction
{
    public function __construct(
        protected TimeSlots $timeSlot,
        protected int $capacity,
    ) {
    }

    public function execute(): TimeSlots
    {
        return TimeSlotBookingLock::run(
            [$this->timeSlot->getId()],
            fn (): TimeSlots => $this->apply()
        );
    }

    private function apply(): TimeSlots
    {
        $timeSlot = $this->timeSlot->refresh();
        $booked = $timeSlot->getBookedSlotsCount();

        if ($this->capacity < 0 || $this->capacity < $booked) {
            throw new ValidationException(
                'Capacity cannot be below the current bookings of this time slot. Booked: ' . $booked
            );
        }

        $timeSlot->update(['initial_capacity' => $this->capacity]);
        $timeSlot->autoUpdateStatus();

        return $timeSlot;
    }
}
