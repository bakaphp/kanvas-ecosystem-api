<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\TeeTime;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\TeeTime\Enums\EventStatusEnum;
use Kanvas\Connectors\TeeTime\Handlers\TeeTimeHandler;
use Kanvas\Connectors\TeeTime\Workflows\Activities\SyncTeeTimeEventActivity;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Participants\Models\ParticipantPass;
use Kanvas\Event\Participants\Models\ParticipantPassMotive;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

final class SyncTeeTimeEventActivityTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;

    public function testEventStatusTransitionToActiveGeneratesCodes(): void
    {
        // Mock notifications
        Notification::fake();

        // Mock the SendEventEmailsAction
        $this->mock(SendEventEmailsAction::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturn(null);
        });

        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company, $app);

        $this->setIntegration(
            $app,
            IntegrationsEnum::TEE_TIME,
            TeeTimeHandler::class,
            $company,
            $user
        );

        // Setup event system
        $setup = new Setup($app, $user, $company);
        $setup->run();

        // Create inventory for booking
        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'tee_time_slot',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($productResponse['id']);
        $variantId = $product->variants()->first()->id;

        $this->addVariantToChannel(
            variantId: (string) $variantId,
            channelId: $channelResponse['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $variantId,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        // Create a booking
        $bookingData = [
            'resources_id' => $variantId,
            'resources_type' => 'variant',
            'start_at' => now()->addDay()->format('Y-m-d') . ' 10:00:00',
            'end_at' => now()->addDay()->format('Y-m-d') . ' 12:00:00',
            'participants' => [
                [
                    'firstname' => 'John',
                    'lastname' => 'Doe',
                    'contacts' => [
                        [
                            'contacts_types_id' => 1,
                            'value' => 'john@example.com',
                            'weight' => 1,
                        ],
                    ],
                ],
                [
                    'firstname' => 'Jane',
                    'lastname' => 'Smith',
                    'contacts' => [
                        [
                            'contacts_types_id' => 1,
                            'value' => 'jane@example.com',
                            'weight' => 1,
                        ],
                    ],
                ],
            ],
            'event_name' => 'Tee Time Reservation',
            'event_description' => 'Golf tee time booking',
            'metadata' => [
                'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
                'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
                'price' => 50.00,
            ],
            'resources' => [
                [
                    'resources_id' => $variantId,
                    'resources_type' => 'variant',
                    'metadata' => [
                        'notes' => 'Golf course reservation',
                    ],
                ],
            ],
        ];

        $response = $this->graphQL('
            mutation bookResource($input: ResourceBookingInput!) {
                bookResource(input: $input) {
                    id
                    event {
                        id
                    }
                }
            }
        ', [
            'input' => $bookingData,
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $this->assertNull($response->json('errors'));

        $eventVersionId = $response->json('data.bookResource.id');
        $eventId = $response->json('data.bookResource.event.id');

        $event = Event::find($eventId);

        // Execute the activity with status transition to ACTIVE
        $activity = new SyncTeeTimeEventActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($event, $app, [
            'currentEventTypeName' => WorkflowEnum::STATUS_TRANSITION->value,
            'to_status' => EventStatusEnum::ACTIVE->value,
        ]);

        // Assert activity executed successfully
        $this->assertEquals('success', $result['status']);
        $this->assertEquals('Event synced correctly', $result['message']);

        // Assert that a ParticipantPassMotive was created
        $motive = ParticipantPassMotive::fromCompany($company)
            ->fromApp($app)
            ->where('name', 'Default')
            ->first();

        $this->assertNotNull($motive);

        // Assert that codes were generated for all participants (2 participants)
        $participantPasses = ParticipantPass::where('event_id', $eventId)
            ->where('participant_id', '>', 0) // Participant-specific passes
            ->get();

        $this->assertCount(2, $participantPasses);

        // Assert that each pass has a unique code
        $codes = $participantPasses->pluck('code')->toArray();
        $this->assertEquals(count($codes), count(array_unique($codes)));

        // Assert that an event-level pass was created (participant_id = null)
        $eventPass = ParticipantPass::where('event_id', $eventId)
            ->whereNull('participant_id')
            ->first();

        $this->assertNotNull($eventPass);
        $this->assertNotEmpty($eventPass->code);

        // Assert all passes are linked to the correct motive
        foreach ($participantPasses as $pass) {
            $this->assertEquals($motive->getId(), $pass->participant_pass_motive_id);
        }

        $this->assertEquals($motive->getId(), $eventPass->participant_pass_motive_id);
    }
}
