<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Kanvas\Connectors\Stripe\Services\StripePaymentService;
use Kanvas\Event\Events\Enums\EventStatusEnum;
use Kanvas\Event\Events\Models\EventHold;
use Tests\Connectors\Traits\HasStripeConfiguration;
use Tests\GraphQL\Souk\Traits\PaymentCases;

class ResourceBookingCrudTest extends ResourceBookingBase
{
    use PaymentCases;
    use HasStripeConfiguration;

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
        // $this->assertEquals(50.00, $updatedEventVersion['metadata']['price']);
        // $this->assertEquals('Updated booking with new time', $updatedEventVersion['metadata']['notes']);

        // Verify updated dates
        $this->assertEquals('14:00:00', $updatedEventVersion['dates'][0]['start_time']);
        $this->assertEquals('16:00:00', $updatedEventVersion['dates'][0]['end_time']);
    }

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

    public function testMultiParticipantResourceBooking(): void
    {
        $bookingData = [
            'resources_id' => $this->variantId,
            'resources_type' => 'variant',
            'start_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'end_at' => now()->addDay()->addHours(2)->format('Y-m-d H:i:s'),
            'participants' => [
                [
                    "firstname" => "Johna",
                    "lastname" => "Doe",
                    "contacts" => [
                        [
                            "contacts_types_id" => 1,
                            "value" => "jdoes@example.com",
                            "weight" => 1
                        ]
                    ]
                ],
                [
                    "firstname" => "Alices",
                    "lastname" => "Smith",
                    "contacts" => [
                        [
                            "contacts_types_id" => 1,
                            "value" => "alices@example.com",
                            "weight" => 1
                        ]
                    ]
                ],
                [
                    "firstname" => "Carlosa",
                    "lastname" => "Martinez",
                    "contacts" => [
                        [
                            "contacts_types_id" => 1,
                            "value" => "carloss@example.com",
                            "weight" => 1
                        ]
                    ]
                ]
            ],
            'event_name' => 'Multi-Resource Booking Test',
            'event_description' => 'Testing booking with multiple resources',
            'metadata' => [
                'price' => 25.00,
                'notes' => 'Test booking'
            ],
            'resources' => [
                [
                    'resources_id' => $this->variantId,
                    'resources_type' => 'variant',
                    'metadata' => [
                        'notes' => 'Additional equipment needed'
                    ]
                ]
            ]
        ];

        $response = $this->graphQL('
            mutation bookResource($input: ResourceBookingInput!) {
                bookResource(input: $input) {
                    id
                    name
                    event {
                        id
                        name
                        resources {
                            resources_id
                            resources_type
                            metadata
                        }
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

        // Verify the event version was created with multiple participants
        $this->assertNotNull($eventVersion['id']);
        $this->assertEquals('Multi-Resource Booking Test', $eventVersion['name']);
        $this->assertNotNull($eventVersion['event']['resources']);
    }

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

    public function testActiveHoldPreventsBooking(): void
    {
        // Create an active hold on a time slot
        EventHold::create([
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\\Inventory\\Variants\\Models\\Variants',
            'start_at' => now()->addDay()->format('Y-m-d') . ' 10:00:00',
            'end_at' => now()->addDay()->format('Y-m-d') . ' 12:00:00',
            'users_id' => $this->user->id,
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->apps->getId(),
            'expires_at' => now()->addMinutes(15), // Active hold
        ]);

        // Try to create a booking in the same time slot (should fail)
        $bookingData = $this->getBasicBookingData();

        $response = $this->graphQL('
            mutation bookResource($input: ResourceBookingInput!) {
                bookResource(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => $bookingData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        // Should have validation error about the hold
        $this->assertNotNull($response->json('errors'));
        $this->assertStringContainsString('held by another user', $response->json('errors.0.message'));
    }

    public function testExpiredHoldAllowsBooking(): void
    {
        // Create an expired hold on a time slot
        EventHold::create([
            'resources_id' => $this->variantId,
            'resources_type' => 'Kanvas\\Inventory\\Variants\\Models\\Variants',
            'start_at' => now()->addDay()->format('Y-m-d') . ' 10:00:00',
            'end_at' => now()->addDay()->format('Y-m-d') . ' 12:00:00',
            'users_id' => $this->user->id,
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->apps->getId(),
            'expires_at' => now()->subMinutes(5), // Expired hold
        ]);

        // Try to create a booking in the same time slot (should succeed)
        $bookingData = $this->getBasicBookingData();

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

        // Should succeed since hold is expired
        $this->assertNull($response->json('errors'));
        $this->assertNotNull($response->json('data.bookResource.id'));
    }

    public function testConfirmBookingWithStripePayment(): void
    {
        $bookingData = $this->getBasicBookingData();
        $eventVersion = $this->createBooking($bookingData);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('processPaymentIntent')
                ->once()
                ->with('pi_test_payment_intent')
                ->andReturn([
                    'id' => 'pi_test_payment_intent',
                    'status' => 'succeeded',
                    'amount' => 2500,
                ]);
        });


        $paymentIntentId = $this->createTestPaymentIntent($bookingData["order_items"][0], $this->apps)->id;


        $confirmData = [
            'event_version_id' => $eventVersion['id'],
            'payment_intent_id' => $paymentIntentId,
        ];

        $response = $this->graphQL('
            mutation confirmBooking($input: ConfirmBookingInput!) {
                confirmBooking(input: $input) {
                    success
                    message
                    event_version {
                        id
                        name
                        event {
                            id
                            eventStatus {
                                name
                            }
                        }
                    }
                    payment
                    order
                }
            }
        ', [
            'input' => $confirmData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $result = $response->json('data.confirmBooking');

        $this->assertTrue($result['success']);
        $this->assertEquals('Payment confirmed and booking activated successfully', $result['message']);
        $this->assertEquals($eventVersion['id'], $result['event_version']['id']);
        $this->assertEquals(EventStatusEnum::ACTIVE->value, $result['event_version']['event']['eventStatus']['name']);
        $this->assertNotNull($result['payment']);
        $this->assertNotNull($result['order']);
    }

    public function testConfirmBookingWithCustomAmount(): void
    {
        $bookingData = $this->getBasicBookingData();
        $eventVersion = $this->createBooking($bookingData);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('processPaymentIntent')
                ->once()
                ->andReturn([
                    'id' => 'pi_test_payment_intent',
                    'status' => 'succeeded',
                    'amount' => 5000,
                ]);
        });

        $confirmData = [
            'event_version_id' => $eventVersion['id'],
            'payment_intent_id' => 'pi_test_payment_intent',
            'amount' => 50.00,
        ];

        $response = $this->graphQL('
            mutation confirmBooking($input: ConfirmBookingInput!) {
                confirmBooking(input: $input) {
                    success
                    message
                    payment
                }
            }
        ', [
            'input' => $confirmData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNull($response->json('errors'));
        $result = $response->json('data.confirmBooking');

        $this->assertTrue($result['success']);
        $this->assertNotNull($result['payment']);
    }

    public function testConfirmBookingFailsForAlreadyPaidOrder(): void
    {
        $bookingData = $this->getBasicBookingData();
        $eventVersion = $this->createBooking($bookingData);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('processPaymentIntent')
                ->once()
                ->andReturn([
                    'id' => 'pi_test_payment_intent_1',
                    'status' => 'succeeded',
                ]);
        });

        $confirmData = [
            'event_version_id' => $eventVersion['id'],
            'payment_intent_id' => 'pi_test_payment_intent_1',
        ];

        $this->graphQL('
            mutation confirmBooking($input: ConfirmBookingInput!) {
                confirmBooking(input: $input) {
                    success
                }
            }
        ', [
            'input' => $confirmData,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->mock(StripePaymentService::class, function ($mock) {
            $mock->shouldReceive('processPaymentIntent')->never();
        });

        $confirmDataSecond = [
            'event_version_id' => $eventVersion['id'],
            'payment_intent_id' => 'pi_test_payment_intent_2',
        ];

        $response = $this->graphQL('
            mutation confirmBooking($input: ConfirmBookingInput!) {
                confirmBooking(input: $input) {
                    success
                }
            }
        ', [
            'input' => $confirmDataSecond,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $this->assertNotNull($response->json('errors'));
        $this->assertStringContainsString('already paid', $response->json('errors.0.message'));
    }
}
