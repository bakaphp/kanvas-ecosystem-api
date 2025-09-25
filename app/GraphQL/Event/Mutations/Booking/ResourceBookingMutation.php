<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Mutations\Booking;

use Baka\Support\Str;
use GraphQL\Type\Definition\ResolveInfo;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Actions\CreateEventAction;
use Kanvas\Event\Events\DataTransferObject\Event as EventDto;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
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
        $holdDuration = $input['hold_duration_minutes'] ?? 15; // Default 15 minutes hold

        // Generate unique hold ID
        $holdId = 'HOLD-' . uniqid() . '-' . time();

        // Store hold information (you might want to use Redis/Cache for better performance)
        $holdData = [
            'hold_id' => $holdId,
            'resources_id' => $input['resources_id'],
            'resources_type' => $input['resources_type'],
            'start_at' => $input['start_at'],
            'end_at' => $input['end_at'],
            'participants' => $input['participants'],
            'event_name' => $input['event_name'] ?? null,
            'event_description' => $input['event_description'] ?? null,
            'metadata' => $input['metadata'] ?? [],
            'resources' => $input['resources'] ?? [],
            'user_id' => $user->getId(),
            'company_id' => $company->getId(),
            'app_id' => $app->getId(),
            'expires_at' => now()->addMinutes($holdDuration)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
        ];

        // Store in app settings as JSON (you might want to use a dedicated table/cache)
        $holdKey = "resource_hold_{$holdId}";
        $app->set($holdKey, json_encode($holdData));

        return [
            'hold_id' => $holdId,
            'expires_at' => $holdData['expires_at'],
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
            $holdKey = "resource_hold_{$input['hold_id']}";
            $holdDataJson = $app->get($holdKey);
            
            if (!$holdDataJson) {
                throw new \Exception('Hold not found or expired: ' . $input['hold_id']);
            }

            $holdData = json_decode($holdDataJson, true);
            
            // Check if hold is expired
            if (now()->isAfter($holdData['expires_at'])) {
                $app->forget($holdKey); // Clean up expired hold
                throw new \Exception('Hold has expired: ' . $input['hold_id']);
            }

            // Use held data as booking data, but allow payment intent to override
            $bookingData = array_merge($holdData, [
                'payment_intent_id' => $input['payment_intent_id'] ?? null,
                'payment_method_id' => $input['payment_method_id'] ?? null,
            ]);

            // Clean up hold after successful retrieval
            $app->forget($holdKey);
        }

        // Validate payment intent if provided
        if (isset($bookingData['payment_intent_id'])) {
            $this->validatePaymentIntent($bookingData['payment_intent_id']);
        }

        // Get the resource entity
        $resource = $this->getResource($bookingData['resources_type'], $bookingData['resources_id']);

        $eventDto = EventDto::from($app, $user, $company, $this->buildEventData($resource, $bookingData, $app, $user));

        $createEventAction = new CreateEventAction($eventDto);
        $event = $createEventAction->execute();

        $eventVersion = $event->versions->first();
        
        // Update metadata with payment and hold information
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
        
        $eventVersion->update(['metadata' => $metadata]);

        return $eventVersion;
    }

    private function buildEventData($resource, array $input, Apps $app, Users $user, Companies $company): array
    {
        $startAt = Carbon::parse($input['start_at']);
        $endAt = Carbon::parse($input['end_at']);
        $eventName = $input['event_name'] ?? $resource->name . $startAt->format('Y-m-d H:i');
        $typeId = $input['metadata']['type_id'] ?? null;

        $eventType = EventType::firstOrCreate([
            'companies_id' => $company->getId(),
            'apps_id' => $app->getId(),
            'id' => $typeId,
        ], [
            'users_id' => $user->getId(),
            'name' => 'Default',
        ]);


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
            'type_id' => $eventType->getId(),
            'class_id' => $input['metadata']['class_id'] ?? null,
            'category_id' => $input['metadata']['category_id'] ?? null,
        ];
    }

    private function getResource(string $resourceType, int|string $resourceId)
    {
        $resourceClass = SystemModules::getSystemModuleNameSpaceBySlug($resourceType);
        return $resourceClass::getById($resourceId);
    }

    /**
     * Validate payment intent (placeholder - implement based on your payment system)
     */
    private function validatePaymentIntent(string $paymentIntentId): void
    {
        // Add your payment validation logic here
        // For example, check with Stripe, PayPal, etc.
        // Throw exception if payment intent is invalid
        
        // Example validation (adjust based on your payment system):
        // $paymentIntent = \Stripe\PaymentIntent::retrieve($paymentIntentId);
        // if ($paymentIntent->status !== 'succeeded') {
        //     throw new \Exception('Payment intent not successful');
        // }
    }
}
