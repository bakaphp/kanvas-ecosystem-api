<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\BuildEventDataAction;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Enums\TimeSlotStatusEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Kanvas\Exceptions\ValidationException;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class TimeSlotBookingMutation
{
    /**
     * Book a time slot and create event with participants using existing CreateEventAction.
     */
    public function book(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];

        $timeSlot = TimeSlots::fromApp($app)
            ->fromCompany($company)
            ->findOrFail($input['time_slot_id']);

        // Get the schedule rule to access metadata for default values
        $scheduleRule = ScheduleRules::where('resources_id', $timeSlot->resources_id)
            ->where('resources_type', $timeSlot->resources_type)
            ->first();

        if (! $scheduleRule) {
            throw new ValidationException('Schedule rule not found for the given time slot resource.');
        }

        $input['start_at'] = $timeSlot->start_at->toDateTimeString();
        $input['end_at'] = $timeSlot->end_at->toDateTimeString();

        $eventData = new BuildEventDataAction($scheduleRule->resource, $user, $input)->execute();

        $eventDto = EventDto::from($app, $user, $company, $eventData);

        $createEventAction = new CreateEventAction($eventDto);
        $event = $createEventAction->execute();

        $eventVersion = $event->versions->first();

        // Link event version to time slot
        $updateData = ['time_slot_id' => $timeSlot->id];

        if (isset($input['metadata']) || isset($input['hold_id'])) {
            $metadata = $input['metadata'] ?? [];
            if (isset($input['hold_id'])) {
                $metadata['hold_id'] = $input['hold_id'];
            }
            $updateData['metadata'] = $metadata;
        }

        $eventVersion->update($updateData);

        $this->updateTimeSlotCapacity($timeSlot, count($input['participants']));

        return $eventVersion;
    }

    private function updateTimeSlotCapacity(TimeSlots $timeSlot, int $participantCount): void
    {
        if ($timeSlot->capacity >= $participantCount) {
            $timeSlot->update([
                'capacity' => $timeSlot->capacity - $participantCount,
                'status' => $timeSlot->capacity - $participantCount <= 0 ? TimeSlotStatusEnum::BOOKED->value : TimeSlotStatusEnum::OPEN->value
            ]);
        }
    }
}
