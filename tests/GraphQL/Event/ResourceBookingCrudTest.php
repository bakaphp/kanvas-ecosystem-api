<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class ResourceBookingCrudTest extends TestCase
{
    use InventoryCases;

    protected $variant;
    protected $region;
    protected $company;
    protected $user;
    protected $apps;
    protected $warehouseResponse;
    protected $channelResponse;

    public function setUp(): void
    {
        parent::setUp();
        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $setup = new Setup($this->apps, $this->user, $this->company);
        $setup->run();
    }

    /**
     * Test creating a resource booking (CREATE operation)
     */
    public function testCreateResourceBooking(): void
    {
        $bookingData = $this->getBasicBookingData();

        $response = $this->graphQL('
            mutation bookResource($input: ResourceBookingInput!) {
                bookResource(input: $input) {
                    id
                    name
                    metadata
                    dates {
                        date
                        start_time
                        end_time
                    }
                    event {
                        id
                        name
                        resources_id
                        resources_type
                    }
                }
            }
        ', [
            'input' => $bookingData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $eventVersion = $response->json('data.bookResource');

        // Verify the booking was created successfully
        $this->assertNotNull($eventVersion['id']);
        $this->assertEquals('Test Resource Booking', $eventVersion['name']);
        $this->assertNotNull($eventVersion['event']['id']);
        $this->assertEquals($bookingData['resources_id'], $eventVersion['event']['resources_id']);
        $this->assertEquals('Kanvas\\Inventory\\Variants\\Models\\Variants', $eventVersion['event']['resources_type']);

        // Verify dates
        $this->assertCount(1, $eventVersion['dates']);
        $this->assertEquals(now()->addDay()->format('Y-m-d'), $eventVersion['dates'][0]['date']);
        $this->assertEquals('10:00:00', $eventVersion['dates'][0]['start_time']);
        $this->assertEquals('12:00:00', $eventVersion['dates'][0]['end_time']);
    }

    /**
     * Test updating a resource booking (UPDATE operation)
     */
    public function testUpdateResourceBooking(): void
    {
        // First create a booking
        $bookingData = $this->getBasicBookingData();
        $createResponse = $this->createBooking($bookingData);
        $eventVersionId = $createResponse['id'];

        // Update the booking
        $updateData = [
            'event_version_id' => $eventVersionId,
            'event_name' => 'Updated Resource Booking',
            'event_description' => 'Updated description',
            'start_at' => now()->addDay()->format('Y-m-d') . ' 14:00:00',
            'end_at' => now()->addDay()->format('Y-m-d') . ' 16:00:00',
            'metadata' => [
                'price' => 50.00,
                'notes' => 'Updated booking with new time'
            ]
        ];

        $response = $this->graphQL('
            mutation updateResourceBooking($input: ResourceBookingUpdateInput!) {
                updateResourceBooking(input: $input) {
                    id
                    name
                    metadata
                    dates {
                        date
                        start_time
                        end_time
                    }
                }
            }
        ', [
            'input' => $updateData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $updatedEventVersion = $response->json('data.updateResourceBooking');

        // Verify the booking was updated successfully
        $this->assertEquals($eventVersionId, $updatedEventVersion['id']);
        $this->assertEquals('Updated Resource Booking', $updatedEventVersion['name']);
        $this->assertEquals(50.00, $updatedEventVersion['metadata']['price']);
        $this->assertEquals('Updated booking with new time', $updatedEventVersion['metadata']['notes']);

        // Verify updated dates
        $this->assertEquals('14:00:00', $updatedEventVersion['dates'][0]['start_time']);
        $this->assertEquals('16:00:00', $updatedEventVersion['dates'][0]['end_time']);
    }

    /**
     * Test deleting a resource booking (DELETE operation)
     */
    public function testDeleteResourceBooking(): void
    {
        // First create a booking
        $bookingData = $this->getBasicBookingData();
        $createResponse = $this->createBooking($bookingData);
        $eventVersionId = $createResponse['id'];

        // Delete the booking
        $response = $this->graphQL('
            mutation deleteResourceBooking($eventVersionId: ID!) {
                deleteResourceBooking(event_version_id: $eventVersionId) {
                    success
                    message
                    deleted_event
                }
            }
        ', [
            'eventVersionId' => $eventVersionId,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $deleteResult = $response->json('data.deleteResourceBooking');

        // Verify the booking was deleted successfully
        $this->assertTrue($deleteResult['success']);
        $this->assertEquals('Resource booking deleted successfully', $deleteResult['message']);
        $this->assertEquals($eventVersionId, $deleteResult['deleted_event']['id']);
    }

    /**
     * Test time slot validation - should prevent double booking
     */
    public function testTimeSlotValidation(): void
    {
        // Create first booking
        $bookingData1 = $this->getBasicBookingData();
        $this->createBooking($bookingData1);

        // Try to create overlapping booking (should fail)
        $bookingData2 = $this->getBasicBookingData();
        $bookingData2['event_name'] = 'Conflicting Booking';
        $bookingData2['start_at'] = now()->addDay()->format('Y-m-d') . ' 11:00:00'; // Overlaps with first booking
        $bookingData2['end_at'] = now()->addDay()->format('Y-m-d') . ' 13:00:00';

        $response = $this->graphQL('
            mutation bookResource($input: ResourceBookingInput!) {
                bookResource(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => $bookingData2,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        // Should have validation error
        $this->assertNotNull($response->json('errors'));
        $this->assertStringContainsString('Time slot is not available', $response->json('errors.0.message'));
    }

    /**
     * Test update time slot validation - should prevent conflicting updates
     */
    public function testUpdateTimeSlotValidation(): void
    {
        // Create two bookings with different time slots
        $bookingData1 = $this->getBasicBookingData();
        $booking1 = $this->createBooking($bookingData1);

        $bookingData2 = $this->getBasicBookingData();
        $bookingData2['event_name'] = 'Second Booking';
        $bookingData2['start_at'] = now()->addDay()->format('Y-m-d') . ' 14:00:00';
        $bookingData2['end_at'] = now()->addDay()->format('Y-m-d') . ' 16:00:00';
        $booking2 = $this->createBooking($bookingData2);

        // Try to update second booking to conflict with first (should fail)
        $updateData = [
            'event_version_id' => $booking2['id'],
            'start_at' => now()->addDay()->format('Y-m-d') . ' 11:00:00', // Conflicts with booking1
            'end_at' => now()->addDay()->format('Y-m-d') . ' 13:00:00',
        ];

        $response = $this->graphQL('
            mutation updateResourceBooking($input: ResourceBookingUpdateInput!) {
                updateResourceBooking(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => $updateData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        // Should have validation error
        $this->assertNotNull($response->json('errors'));
        $this->assertStringContainsString('Time slot is not available', $response->json('errors.0.message'));
    }

    /**
     * Test successful update to non-conflicting time slot
     */
    public function testSuccessfulNonConflictingUpdate(): void
    {
        // Create a booking
        $bookingData = $this->getBasicBookingData();
        $booking = $this->createBooking($bookingData);

        // Update to a non-conflicting time slot (should succeed)
        $updateData = [
            'event_version_id' => $booking['id'],
            'start_at' => now()->addDay()->format('Y-m-d') . ' 18:00:00', // Non-conflicting time
            'end_at' => now()->addDay()->format('Y-m-d') . ' 20:00:00',
        ];

        $response = $this->graphQL('
            mutation updateResourceBooking($input: ResourceBookingUpdateInput!) {
                updateResourceBooking(input: $input) {
                    id
                    dates {
                        start_time
                        end_time
                    }
                }
            }
        ', [
            'input' => $updateData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $updatedBooking = $response->json('data.updateResourceBooking');

        // Verify the update was successful
        $this->assertEquals('18:00:00', $updatedBooking['dates'][0]['start_time']);
        $this->assertEquals('20:00:00', $updatedBooking['dates'][0]['end_time']);
    }

    /**
     * Helper method to get basic booking data
     */
    private function getBasicBookingData(): array
    {
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct()->json()['data']['createProduct'];
        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $warehouseResponse['id']]
        )->json()['data']['createVariant'];

        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        return [
            'resources_id' => $variantResponse['id'],
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
                            'weight' => 1
                        ]
                    ]
                ]
            ],
            'event_name' => 'Test Resource Booking',
            'event_description' => 'Test booking description',
            'metadata' => [
                'category_id' => EventCategory::fromCompany($company)->fromApp($this->apps)->first()->getId(),
                'type_id' => EventType::fromCompany($company)->fromApp($this->apps)->first()->getId(),
                'price' => 25.00,
                'notes' => 'Test booking'
            ]
        ];
    }

    /**
     * Helper method to create a booking
     */
    private function createBooking(array $bookingData): array
    {
        $response = $this->graphQL('
            mutation bookResource($input: ResourceBookingInput!) {
                bookResource(input: $input) {
                    id
                    name
                    event {
                        id
                    }
                }
            }
        ', [
            'input' => $bookingData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        return $response->json('data.bookResource');
    }
}