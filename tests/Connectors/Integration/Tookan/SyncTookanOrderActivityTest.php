<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Tookan;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Connectors\Movipass\Workflows\Activities\SyncTookanOrderActivity;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;
use Kanvas\Connectors\Tookan\Handlers\TookanHandler;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\GraphQL\Souk\Traits\PaymentCases;
use Tests\TestCase;

final class SyncTookanOrderActivityTest extends TestCase
{
    use HasIntegrationCompany;
    use InventoryCases;
    use PaymentCases;

    public function testTookanCreationWorkflow(): void
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $region = Regions::getDefault($company ?? $company, $app);

        $app->set(ConfigurationEnum::API_KEY->value, env('TEST_TOOKAN_API_KEY'));
        $app->set(ConfigurationEnum::BASE_URL->value, env('TEST_TOOKAN_BASE_URL', ConfigurationEnum::SANDBOX_URL->value));

        $this->setIntegration(
            $app,
            IntegrationsEnum::TOOKAN,
            TookanHandler::class,
            $company,
            $user
        );

        $this->setAllowNoPaymentStatus(true, $app);

        $warehouseResponse = $this->createWarehouses((string) $region->getId())->json()['data']['createWarehouse'];
        $productResponse = $this->createProduct(attributes: [])->json();
        //dump('product response', $productResponse);
        $productResponse = $productResponse['data']['createProduct'];

        $warehouseData = [
            'id' => $warehouseResponse['id'],
        ];

        $product = Products::fromApp($app)->find($productResponse['id']);

        $channelResponse = $this->createChannel()->json()['data']['createChannel'];

        $this->addVariantToChannel(
            variantId: (string) $product->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product->variants->first()->id,
            warehouseId: $warehouseResponse['id'],
            amount: 100
        );

        // Create second company within the same app
        $company2 = Companies::factory()->create([
            'apps_id' => $app->getId(),
            'name' => 'Second Test Company',
        ]);

        $company2->associateUser(
            $user,
            true,
            $company2->defaultBranch
        );

        // Create product and variant for second company
        $productResponse2 = $this->graphQL('
            mutation createProduct($input: ProductInput!) {
                createProduct(input: $input) {
                    id
                    name
                }
            }
        ', [
            'input' => [
                'name' => 'Second Company Product',
                'slug' => 'second-company-product-' . time(),
                'description' => 'Product from second company',
                'attributes' => [
                    [
                        'name' => 'slots',
                        'value' => '50',
                    ],
                ],
            ],
        ], [], [
            'X-Kanvas-Location' => $company2->defaultBranch->uuid,
            'X-Kanvas-App' => $app->key,
        ])->json();

        $product2 = Products::fromApp($app)
            ->where('companies_id', $company2->getId())
            ->find($productResponse2['data']['createProduct']['id']);

        // Add second company variant to channel
        $this->addVariantToChannel(
            variantId: (string) $product2->variants->first()->id,
            channelId: $channelResponse['id'],
            warehouseData: $warehouseData
        );

        $this->addVariantToWarehouse(
            variantId: (string) $product2->variants->first()->id,
            warehouseId: $warehouseResponse['id'],
            amount: 50
        );

        $data = [
            'cartId' => 0,
            'customer' => [
                'email' => fake()->email(),
            ],
            'order_type' => OrderTypeEnum::IMPOUND_LOT->value,
            'metadata' => [
                'data' => [],
            ],
            'items' => [
                [
                    'variant_id' => (string) $product->variants->first()->id,
                    'quantity' => 1,
                    'price' => 100,
                ],
                [
                    'variant_id' => (string) $product2->variants->first()->id,
                    'quantity' => 1,
                    'price' => 50,
                ],
            ],
            'reference' => 'Charge for impound lot',
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
            'X-Kanvas-Location' => $company->branch->uuid,
            'X-Kanvas-App' => $app->key,
        ]);

        $order = $response->json('data.createOrderFromCart.order');
        $order = Order::fromApp($app)->find($order['id']);

        $activity = new SyncTookanOrderActivity(
            0,
            now()->toDateTimeString(),
            StoredWorkflow::make(),
            []
        );

        $result = $activity->execute($order, $app, [
            'currentEventTypeName' => WorkflowEnum::CREATED->value,
        ]);

        $order->refresh();
        $this->assertEquals($result['status'], 'success');
        $this->assertEquals($result['message'], 'Order synced correctly');
        // $this->assertEquals($order->resource->companies_id, $company2->id);
    }
}
