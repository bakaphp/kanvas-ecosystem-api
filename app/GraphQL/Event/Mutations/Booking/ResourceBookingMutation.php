<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use Baka\Support\Str;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\SystemModules\Models\SystemModules;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ResourceBookingMutation
{
    /**
     * Book a resource directly and create event with participants using existing CreateEventAction.
     */
    public function book(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];

        // Get the resource entity
        $resource = $this->getResource($input['resources_type'], $input['resources_id']);

        $eventDto = EventDto::from($app, $user, $company, $this->buildEventData($resource, $input));

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

        return $eventVersion;
    }

    private function buildEventData($resource, array $input): array
    {
        $startAt = Carbon::parse($input['start_at']);
        $endAt = Carbon::parse($input['end_at']);
        $eventName = $input['event_name'] ?? $resource->name . $startAt->format('Y-m-d H:i');

        return [
            'name' => $eventName,
            'description' => $input['event_description'] ?? $resource->description,
            'slug' => Str::simpleSlug($eventName),
            'resources_id' => (string) $resource->id,
            'resources_type' => $resource->getMorphClass(),
            'participants' => $input['participants'],
            'resources' => $input['resources'],
            'dates' => [
                [
                    'date' => $startAt->toDateString(),
                    'start_time' => $startAt->format('H:i'),
                    'end_time' => $endAt->format('H:i'),
                ]
            ],
            'theme_id' => $input['metadata']['theme_id'] ?? null,
            'theme_area_id' => $input['metadata']['theme_area_id'] ?? null,
            'status_id' => $input['metadata']['status_id'] ?? null,
            'type_id' => $input['metadata']['type_id'] ?? null,
            'class_id' => $input['metadata']['class_id'] ?? null,
            'category_id' => $input['metadata']['category_id'] ?? null,
        ];
    }

    private function getResource(string $resourceType, int|string $resourceId)
    {
        $resourceClass = SystemModules::getSystemModuleNameSpaceBySlug($resourceType);
        return $resourceClass::getById($resourceId);
    }
}
