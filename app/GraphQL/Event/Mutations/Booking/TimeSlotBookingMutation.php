<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use Baka\Support\Str;
use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\BuildEventDataAction;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Enums\TimeSlotStatusEnum;
use Kanvas\Event\Events\Models\EventVersion;
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

        // Validate capacity before booking
        $participantCount = count($input['participants'] ?? []);
        if (!$timeSlot->hasAvailableCapacity($participantCount)) {
            throw new ValidationException('Time slot does not have enough available capacity. Available: ' . $timeSlot->getAvailableSlots() . ', Required: ' . $participantCount);
        }
        // Extract resource information from the time slot
        $bookingData = $input;
        $bookingData['resources_id'] = $timeSlot->resources_id;
        $bookingData['resources_type'] = $timeSlot->resources_type;
        $bookingData['start_at'] = $timeSlot->start_at->toDateTimeString();
        $bookingData['end_at'] = $timeSlot->end_at->toDateTimeString();
        $bookingData['time_slot_id'] = $timeSlot->id;

        // Use the resource from the time slot
        $resource = $timeSlot->resource;

        if (! $resource) {
            throw new ValidationException('Resource not found for the given time slot.');
        }

        // Build event data using the same action as bookResource
        $eventData = new BuildEventDataAction($resource, $user, $company, $bookingData)->execute();
        $eventDto = EventDto::from($app, $user, $company, $eventData);
        $metadata = $bookingData['metadata'] ?? [];
        $metadata['time_slot_id'] = $timeSlot->id;
        $metadata['resource_name'] = $resource?->name ?? '';
        $metadata['resource_type'] = $bookingData['resources_type'];
        $metadata['slug_suffix'] = Str::uuid()->toString();

        if (isset($bookingData['hold_id'])) {
            $metadata['hold_id'] = $bookingData['hold_id'];
        }

        $createEventAction = new CreateEventAction($eventDto, $metadata);
        $event = $createEventAction->execute();

        $eventVersion = $event->versions->first();

        // Update time slot status based on available capacity
        $newStatus = $timeSlot->isFullyBooked()
            ? TimeSlotStatusEnum::BOOKED->value
            : TimeSlotStatusEnum::OPEN->value;

        $timeSlot->update(['status' => $newStatus]);

        return $eventVersion;
    }
}
