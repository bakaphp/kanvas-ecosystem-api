<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Variants\Actions\AddVariantToChannelAction;
use Kanvas\Inventory\Variants\DataTransferObject\VariantChannel;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Souk\Orders\Enums\OrderStatusEnum;
use Kanvas\Souk\Orders\Models\Order;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

class DraftOrderStatusTest extends TestCase
{
    use InventoryCases;
    use PaymentCases;

    private const UPDATE_DRAFT_STATUS_MUTATION = '
        mutation updateDraftOrderStatus($order_id: ID!, $status: OrderStatusEnum!) {
            updateDraftOrderStatus(order_id: $order_id, status: $status) {
                id
                status
            }
        }
    ';

    /**
     * Creates a draft order following the same pattern as OrderTest::testCreateDraftOrder.
     *
     * @return array{order: Order, company: \Kanvas\Companies\Models\Companies}
     */
    private function createDraftOrderForTest(): array
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $this->setupInventory($app, $company, $user);
        $this->setAllowNoPaymentStatus(true, $app);

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 10,
                'price' => 10.00,
            ]]
        );
        (new CreateProductAction($productData, $user))->execute();

        $variantWarehouse = VariantsWarehouses::whereHas('variant', function ($query) use ($app, $company) {
            $query->where('apps_id', $app->getId())->where('companies_id', $company->getId());
        })->first();

        $channel = Channels::getDefault($company, $app);

        (new AddVariantToChannelAction(
            $variantWarehouse,
            $channel,
            VariantChannel::from([
                'price' => 10.00,
                'discounted_price' => 10.00,
                'is_published' => 10.00 > 0,
                'config' => null,
            ])
        ))->execute();

        $region = $variantWarehouse->warehouse->region;
        $company = $region->company;

        $response = Order::withoutSyncingToSearch(fn () => $this->graphQL('
            mutation createDraftOrder($input: DraftOrderInput!) {
                createDraftOrder(input: $input) {
                    id
                    status
                }
            }
        ', [
            'input' => [
                'email' => fake()->email(),
                'region_id' => $region->getId(),
                'metadata' => hash('sha256', random_bytes(10)),
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
                        'variant_id' => $variantWarehouse->variant->getId(),
                        'quantity' => 1,
                        'channel_id' => $channel->getId(),
                    ],
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]));

        $response->assertSuccessful();
        $orderId = $response->json('data.createDraftOrder.id');
        $this->assertNotNull($orderId, 'createDraftOrder failed: ' . json_encode($response->json('errors')));
        $order = Order::find($orderId);

        return [
            'order' => $order,
            'company' => $company,
        ];
    }

    public function testUpdateDraftOrderStatusToPending(): void
    {
        $data = $this->createDraftOrderForTest();
        $order = $data['order'];
        $company = $data['company'];

        $this->assertEquals(OrderStatusEnum::DRAFT->value, $order->status);

        $response = Order::withoutSyncingToSearch(fn () => $this->graphQL(self::UPDATE_DRAFT_STATUS_MUTATION, [
            'order_id' => $order->id,
            'status' => 'PENDING',
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]));

        $response->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::PENDING->value, $order->status);
    }

    public function testUpdateDraftOrderStatusToCompleted(): void
    {
        $data = $this->createDraftOrderForTest();
        $order = $data['order'];
        $company = $data['company'];

        $response = Order::withoutSyncingToSearch(fn () => $this->graphQL(self::UPDATE_DRAFT_STATUS_MUTATION, [
            'order_id' => $order->id,
            'status' => 'COMPLETED',
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]));

        $response->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::COMPLETED->value, $order->status);
    }

    public function testUpdateDraftOrderStatusToCanceled(): void
    {
        $data = $this->createDraftOrderForTest();
        $order = $data['order'];
        $company = $data['company'];

        $response = Order::withoutSyncingToSearch(fn () => $this->graphQL(self::UPDATE_DRAFT_STATUS_MUTATION, [
            'order_id' => $order->id,
            'status' => 'CANCELED',
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]));

        $response->assertSuccessful();

        $order->refresh();
        $this->assertEquals(OrderStatusEnum::CANCELED->value, $order->status);
    }

    public function testCannotUpdateNonDraftOrderStatus(): void
    {
        $data = $this->createDraftOrderForTest();
        $order = $data['order'];
        $company = $data['company'];

        Order::withoutSyncingToSearch(function () use ($order) {
            $order->status = OrderStatusEnum::PENDING->value;
            $order->saveOrFail();
        });

        $response = Order::withoutSyncingToSearch(fn () => $this->graphQL(self::UPDATE_DRAFT_STATUS_MUTATION, [
            'order_id' => $order->id,
            'status' => 'COMPLETED',
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]));

        $response->assertGraphQLErrorMessage('Only draft orders can have their status updated through this mutation');
    }

    public function testCannotUpdateDraftOrderStatusToDraft(): void
    {
        $data = $this->createDraftOrderForTest();
        $order = $data['order'];
        $company = $data['company'];

        $response = Order::withoutSyncingToSearch(fn () => $this->graphQL(self::UPDATE_DRAFT_STATUS_MUTATION, [
            'order_id' => $order->id,
            'status' => 'DRAFT',
        ], [], [
            'X-Kanvas-Location' => $company->branch->uuid,
        ]));

        $response->assertGraphQLErrorMessage('Order is already in draft status');
    }
}
