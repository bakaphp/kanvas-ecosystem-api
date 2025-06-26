<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class OrderBase extends TestCase
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
    }

    public function createDraftOrder(
        array $metadata,
        string $variantId,
        int $quantity = 1,
    ): Order {
        $data = [
            'email' => fake()->email(),
            'region_id' => $this->region->getId(),
            'metadata' => $metadata,
            'customer' => [
                'firstname' => fake()->firstName(),
                'lastname' => fake()->lastName(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                ],
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $order = Order::find($response->json('data.createDraftOrder.id'));

        return $order;
    }

    public function createOrderFromCart(
        array $metadata,
        string $variantId,
        int $quantity = 1,
        string $orderType = 'order'
    ): Order {
        $data = [
            'cartId' => 0,
            'metadata' => $metadata,
            'customer' => [
                'email' => fake()->email(),
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'items' => [
                [
                    'variant_id' => $variantId,
                    'quantity' => $quantity,
                ],
            ],
            'order_type' => $orderType,
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrderFromCart($input: OrderCartInput!) {
                createOrderFromCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => $data,
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $order = Order::find($response->json('data.createOrderFromCart.order.id'));

        return $order;
    }
}
