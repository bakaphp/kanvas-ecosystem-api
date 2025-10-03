<?php

declare(strict_types=1);

namespace Tests\GraphQL\Event;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Event\Events\Actions\SendEventEmailsAction;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Support\Setup;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

abstract class ResourceBookingBase extends TestCase
{
    use InventoryCases;

    protected $variant;
    protected $region;
    protected $company;
    protected $user;
    protected $apps;
    protected $warehouseResponse;
    protected $channelResponse;
    protected $productResponse;
    protected $variantId;

    public function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->mock(SendEventEmailsAction::class, function ($mock) {
            $mock->shouldReceive('execute')->andReturn(null);
        });

        $this->apps = app(Apps::class);
        $this->user = Auth::user();
        $this->company = $this->user->getCurrentCompany();
        $this->region = Regions::getDefault($this->company, $this->apps);

        $this->warehouseResponse = $this->createWarehouses((string) $this->region->getId())->json()['data']['createWarehouse'];
        $this->channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->productResponse = $this->createProduct(attributes: [
            [
                'name' => 'event_slot',
                'value' => 100,
            ],
        ])->json()['data']['createProduct'];

        $product = Products::find($this->productResponse['id']);
        $this->variantId = $product->variants()->first()->id;

        $this->addVariantToChannel(
            variantId: (string) $this->variantId,
            channelId: $this->channelResponse['id'],
            warehouseData: [
                'id' => $this->warehouseResponse['id'],
            ]
        );

        $this->addVariantToWarehouse(
            variantId: (string) $this->variantId,
            warehouseId: $this->warehouseResponse['id'],
            amount: 100
        );

        $setup = new Setup($this->apps, $this->user, $this->company);
        $setup->run();
    }

    protected function getBasicBookingData(): array
    {
        return [
            'resources_id' => $this->variantId,
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
                        ]
                    ]
                ]
            ],
            'event_name' => 'Test Resource Booking',
            'event_description' => 'Test booking description',
            'metadata' => [
                'category_id' => EventCategory::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                'type_id' => EventType::fromCompany($this->company)->fromApp($this->apps)->first()->getId(),
                'price' => 25.00,
                'notes' => 'Test booking',
            ],
            'resources' => [
                [
                    'resources_id' => $this->variantId,
                    'resources_type' => 'variant',
                    'metadata' => [
                        'notes' => 'Additional equipment needed',
                    ]
                ]
            ],
        ];
    }

    protected function createBooking(array $bookingData): array
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

        return $response->json('data.bookResource');
    }
}
