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

class ReservationsTest extends TestCase
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

    /// start from this line
    public function testResourceBooking(): void
    {
        $app = app(Apps::class);
        $regionResponse = $this->createRegion()->json()['data']['createRegion'];
        $warehouseResponse = $this->createWarehouses($regionResponse['id'])->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [
            [
                'name' => 'capacity',
                'value' => 10
            ]
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ],
            attributes: [
                [
                    'name' => 'timezone',
                    'value' => 'America/New_York',
                ],
            ]
        )->json()['data']['createVariant'];

        // Create additional resources for the multi-resource booking
        $productResponse2 = $this->createProduct(attributes: [
            [
                'name' => 'type',
                'value' => 'equipment'
            ]
        ])->json()['data']['createProduct'];

        $variantResponse2 = $this->createVariant(
            productId: $productResponse2['id'],
            warehouseData: [
                'id' => $warehouseResponse['id'],
            ]
        )->json()['data']['createVariant'];

        $region = Regions::find($regionResponse['id']);
        $company = $region->company;

        // Test resource booking with multiple resources
        $bookingData = [
            'resources_id' => $variantResponse['id'],
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
            'hold_id' => 'HOLD-' . uniqid(),
            'metadata' => [
                'category_id' => EventCategory::fromCompany($company)->fromApp($app)->first()->getId(),
                'type_id' => EventType::fromCompany($company)->fromApp($app)->first()->getId(),
                // 'theme_id' => '37',
                // 'theme_area_id' => '39',
                // 'status_id' => '37',
                // 'class_id' => '37',
                'price' => 25.00,
                'notes' => 'Test booking'
            ],
            'resources' => [
                [
                    'resources_id' => $variantResponse2['id'],
                    'resources_type' => 'variant',
                    'quantity' => 2,
                    'metadata' => [
                        'notes' => 'Additional equipment needed'
                    ]
                ]
            ]
        ];

        // Perform GraphQL mutation to book resource
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
                            quantity
                            metadata
                        }
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
        $eventVersion = $response->json('data.bookResource');

        // Verify the event version was created
        $this->assertNotNull($eventVersion['id']);
        $this->assertEquals('Multi-Resource Booking Test', $eventVersion['name']);

        // Verify the event was created with the main resource
        $event = $eventVersion['event'];
        $this->assertNotNull($event['id']);
        $this->assertEquals('Multi-Resource Booking Test', $event['name']);

        // Verify the additional resources were stored in the pivot table
        $this->assertCount(1, $event['resources']);
        $resource = $event['resources'][0];
        $this->assertEquals($variantResponse2['id'], $resource['resources_id']);
        $this->assertEquals('variant', $resource['resources_type']);
        $this->assertEquals(2, $resource['quantity']);
        $this->assertArrayHasKey('notes', $resource['metadata']);
        $this->assertEquals('Additional equipment needed', $resource['metadata']['notes']);
    }
}
