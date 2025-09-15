<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use GraphQL\Type\Definition\ResolveInfo;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Enums\TimeSlotStatusEnum;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Event\Events\Models\TimeSlots;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Spatie\LaravelData\DataCollection;

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

        $eventDto = EventDto::from($app, $user, $company, $this->buildEventData($timeSlot, $scheduleRule, $input));

        $createEventAction = new CreateEventAction($eventDto);
        $event = $createEventAction->execute();

        $eventVersion = $event->versions->first();
        if (isset($input['metadata']) || isset($input['hold_id'])) {
            $metadata = $input['metadata'] ?? [];
            if (isset($input['hold_id'])) {
                $metadata['hold_id'] = $input['hold_id'];
            }
            $eventVersion->update(['metadata' => $metadata]);
        }

        $this->updateTimeSlotCapacity($timeSlot, count($input['participants']));

        return $eventVersion;
    }

    private function buildEventData(TimeSlots $timeSlot, ?ScheduleRules $scheduleRule, array $input): array
    {
        $ruleMetadata = $scheduleRule?->metadata ?? [];

        return [
            'name' => $input['event_name'],
            'description' => $input['event_description'] ?? null,
            'slug' => $input['event_name'],
            'resources_id' => (string) $timeSlot->resources_id,
            'resources_type' => $timeSlot->resources_type,
            'participants' => $input['participants'],
            'dates' => [
                [
                    'date' => $timeSlot->start_at->toDateString(),
                    'start_time' => $timeSlot->start_at->format('H:i'),
                    'end_time' => $timeSlot->end_at->format('H:i'),
                ]
            ],
            'theme_id' => $ruleMetadata['theme_id'] ?? $input["metadata"]["theme_id"],
            'theme_area_id' => $ruleMetadata['theme_area_id'] ?? $input["metadata"]['theme_area_id'] ,
            'status_id' => $ruleMetadata['event_status_id'] ?? $input["metadata"]['status_id'],
            'type_id' => $ruleMetadata['event_type_id'] ?? $input["metadata"]['type_id'],
            'class_id' => $ruleMetadata['event_class_id'] ?? $input["metadata"]['class_id'],
            'category_id' => $ruleMetadata['event_category_id'] ?? $input["metadata"]['category_id'],
        ];
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