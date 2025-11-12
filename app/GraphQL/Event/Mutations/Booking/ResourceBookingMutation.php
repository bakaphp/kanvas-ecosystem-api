<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\BuildEventDataAction;
use Kanvas\Event\Events\Actions\CancelEventAction;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\Actions\UpdateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Models\EventHold;
use Kanvas\Event\Events\Models\EventStatus;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Themes\Models\Theme;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Exceptions\ValidationException;
use Kanvas\SystemModules\Models\SystemModules;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;

class ResourceBookingMutation
{
    /**
     * Hold a resource for checkout (temporary reservation)
     */
    public function hold(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];
        $holdDuration = $input['hold_duration_minutes'] ?? $app->get('hold_duration_minutes');

        // Create event hold record
        $eventHold = EventHold::create([
            'resources_id' => $input['resources_id'],
            'resources_type' => $input['resources_type'],
            'start_at' => $input['start_at'],
            'end_at' => $input['end_at'],
            'participants' => $input['participants'],
            'event_name' => $input['event_name'] ?? null,
            'event_description' => $input['event_description'] ?? null,
            'metadata' => $input['metadata'] ?? [],
            'resources' => $input['resources'] ?? [],
            'users_id' => $user->getId(),
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'expires_at' => now()->addMinutes($holdDuration),
        ]);

        return [
            'hold_id' => (string) $eventHold->id,
            'expires_at' => $eventHold->expires_at->toDateTimeString(),
            'message' => "Resource held successfully for {$holdDuration} minutes",
        ];
    }

    /**
     * Book a resource directly and create event with participants using existing CreateEventAction.
     * Can accept either direct booking data or a hold_id from previous hold operation.
     */
    public function book(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];
        $bookingData = $input;

        // If hold_id is provided, retrieve held data
        if (isset($input['hold_id'])) {
            $eventHold = EventHold::fromApp($app)->notExpired()->find($input['hold_id']);

            if (! $eventHold) {
                throw new ValidationException('Hold not found or expired: ' . $input['hold_id']);
            }

            // Clean up hold after successful retrieval
            $eventHold->delete();
        }

        $resource = $this->getResource($bookingData['resources_type'], $bookingData['resources_id']);

        $eventData = new BuildEventDataAction($resource, $user, $company, $bookingData)->execute();
        $metadata = $bookingData['metadata'] ?? [];
        if (isset($bookingData['hold_id'])) {
            $metadata['hold_id'] = $bookingData['hold_id'];
        }
        if (isset($bookingData['payment_intent_id'])) {
            $metadata['payment_intent_id'] = $bookingData['payment_intent_id'];
        }
        if (isset($bookingData['payment_method_id'])) {
            $metadata['payment_method_id'] = $bookingData['payment_method_id'];
        }

        $eventDto = EventDto::from($app, $user, $company, $eventData);

        $createEventAction = new CreateEventAction($eventDto, $metadata);
        $event = $createEventAction->execute();

        $eventVersion = $event->versions->first();

        return $eventVersion;
    }

    private function getResource(string $resourceType, int|string $resourceId)
    {
        $resourceClass = SystemModules::getSystemModuleNameSpaceBySlug($resourceType);

        return $resourceClass::getById($resourceId);
    }

    /**
     * Update an existing resource booking
     */
    public function update(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): EventVersion
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $input = $args['input'];
        $eventVersionId = $input['event_version_id'];

        // Find the existing event version
        $eventVersion = EventVersion::where('id', $eventVersionId)
            ->fromCompany($company)
            ->fromApp($app)
            ->firstOrFail();

        // Prepare update data
        $updateData = [];

        // Basic fields
        if (isset($input['event_name'])) {
            $updateData['name'] = $input['event_name'];
        }

        if (isset($input['event_description'])) {
            $updateData['description'] = $input['event_description'];
        }

        if (isset($input['participants'])) {
            $updateData['participants'] = $input['participants'];
        }

        if (isset($input['resources'])) {
            $updateData['resources'] = $input['resources'];
        }

        // Handle resource updates
        if (isset($input['resources_id']) && isset($input['resources_type'])) {
            $resource = $this->getResource($input['resources_type'], $input['resources_id']);
            $updateData['resources_id'] = (string) $resource->id;
            $updateData['resources_type'] = $resource->getMorphClass();
        }

        // Handle date updates
        if (isset($input['start_at']) && isset($input['end_at'])) {
            $startAt = Carbon::parse($input['start_at']);
            $endAt = Carbon::parse($input['end_at']);

            $updateData['dates'] = [
                [
                    'date' => $startAt->toDateString(),
                    'start_time' => $startAt->format('H:i'),
                    'end_time' => $endAt->format('H:i'),
                ],
            ];
        }

        // Handle metadata and theme/status updates
        if (isset($input['metadata'])) {
            $updateData['metadata'] = $input['metadata'];

            // Create/find theme if needed
            if (isset($input['metadata']['theme_name'])) {
                $theme = Theme::firstOrCreate([
                    'companies_id' => $company->getId(),
                    'apps_id' => $app->getId(),
                    'name' => $input['metadata']['theme_name'],
                ], [
                    'users_id' => $user->getId(),
                ]);
                $updateData['theme_id'] = $theme->id;
            }

            // Create/find theme area if needed
            if (isset($input['metadata']['theme_area_name'])) {
                $themeArea = ThemeArea::firstOrCreate([
                    'companies_id' => $company->getId(),
                    'apps_id' => $app->getId(),
                    'name' => $input['metadata']['theme_area_name'],
                ], [
                    'users_id' => $user->getId(),
                ]);
                $updateData['theme_area_id'] = $themeArea->id;
            }

            // Create/find status if needed
            if (isset($input['metadata']['status_name'])) {
                $eventStatus = EventStatus::firstOrCreate([
                    'companies_id' => $company->getId(),
                    'apps_id' => $app->getId(),
                    'name' => $input['metadata']['status_name'],
                ], [
                    'users_id' => $user->getId(),
                ]);
                $updateData['status_id'] = $eventStatus->id;
            }
        }

        $updateAction = new UpdateEventAction($eventVersion, $updateData);
        $updatedEventVersion = $updateAction->execute();

        return $updatedEventVersion;
    }

    /**
     * Delete a resource booking
     */
    public function delete(mixed $root, array $args, GraphQLContext $context, ResolveInfo $info): array
    {
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);

        $eventVersionId = $args['event_version_id'];

        // Find the existing event version
        $eventVersion = EventVersion::where('id', $eventVersionId)
            ->where('companies_id', $company->getId())
            ->where('apps_id', $app->getId())
            ->firstOrFail();

        // Cancel the event using the action
        $cancelEventAction = new CancelEventAction($eventVersion);
        $cancelledEventVersion = $cancelEventAction->execute();

        // Store event info for response
        $eventInfo = [
            'id' => $cancelledEventVersion->id,
            'name' => $cancelledEventVersion->name,
            'cancelled_at' => $cancelledEventVersion->metadata['cancelled_at'],
            'status' => $cancelledEventVersion->metadata['status'],
        ];

        return [
            'success' => true,
            'message' => 'Resource booking deleted successfully',
            'deleted_event' => $eventInfo,
        ];
    }
}
