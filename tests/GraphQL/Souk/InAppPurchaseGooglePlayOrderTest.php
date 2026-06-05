<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;
use Imdhemy\GooglePlay\Products\ProductPurchase;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\InAppPurchase\Actions\CreateOrderFromGoogleReceiptAction;
use Kanvas\Connectors\InAppPurchase\DataTransferObject\GooglePlayInAppPurchaseReceipt;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\OrderItem;
use Override;
use Tests\TestCase;

class InAppPurchaseGooglePlayOrderTest extends TestCase
{
    private function createMockedAction(GooglePlayInAppPurchaseReceipt $dto): CreateOrderFromGoogleReceiptAction
    {
        return new class ($dto) extends CreateOrderFromGoogleReceiptAction {
            #[Override]
            protected function verifyReceipt(array $receipt): ProductPurchase
            {
                return new ProductPurchase([
                    'purchaseState' => 0,
                    'consumptionState' => 0,
                    'orderId' => $receipt['orderId'] ?? 'GPA.mock',
                    'purchaseToken' => $receipt['purchaseToken'] ?? 'mock_token',
                    'acknowledgementState' => 1,
                ]);
            }
        };
    }

    public function testCreateOrderFromGooglePlayInAppPurchase()
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();
        $app->set(ConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value, 'com.test.app');

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        $receipt = [
            'product_id' => 'premium_prompt_10',
            'order_id' => 'GPA.3368-8891-7367-12318',
            'purchase_token' => 'test_token_' . fake()->uuid(),
        ];

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: $receipt['product_id'],
            sku: $receipt['product_id'],
            warehouses: [[
                'quantity' => 10,
                'price' => 0.29,
            ],
            ]
        );
        (new CreateProductAction($productData, $user))->execute();

        $dto = GooglePlayInAppPurchaseReceipt::from($app, $company, $user, $region, $receipt);
        $order = $this->createMockedAction($dto)->execute();

        $this->assertNotNull($order->id);
        $this->assertTrue($order->hasTag(['iap']));
    }

    public function testCreateOrderFromGooglePlayInAppPurchaseWithCustomFields()
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();
        $app->set(ConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value, 'com.test.app');

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        $productId = 'premium_prompt_cf_' . fake()->uuid();
        $receipt = [
            'product_id' => $productId,
            'order_id' => 'GPA.3368-8891-7367-12318',
            'purchase_token' => 'test_token_' . fake()->uuid(),
            'custom_fields' => [
                [
                    'name' => 'message_id',
                    'value' => '48843',
                ],
            ],
        ];

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: $receipt['product_id'],
            sku: $receipt['product_id'],
            warehouses: [[
                'quantity' => 10,
                'price' => 0.29,
            ],
            ]
        );
        (new CreateProductAction($productData, $user))->execute();

        $dto = GooglePlayInAppPurchaseReceipt::from($app, $company, $user, $region, $receipt);
        $order = $this->createMockedAction($dto)->execute();

        $this->assertNotNull($order->id);
        $this->assertEquals('48843', $order->get('message_id'));
    }

    public function testCreateOrderFromGooglePlayInAppPurchaseWithCustomFieldsVariants()
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();
        $app->set(ConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value, 'com.test.app');

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        $productId = 'premium_prompt_var_' . fake()->uuid();
        $variantSku = 'variant_gp_' . fake()->uuid();
        $receipt = [
            'product_id' => $productId,
            'order_id' => 'GPA.3368-8891-7367-12318',
            'purchase_token' => 'test_token_' . fake()->uuid(),
            'custom_fields' => [
                [
                    'name' => 'message_id',
                    'value' => '48843',
                ],
                [
                    'name' => 'variants_skus',
                    'value' => [
                        ['sku' => $variantSku, 'quantity' => 2],
                    ],
                ],
            ],
        ];

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: $receipt['product_id'],
            sku: $receipt['product_id'],
            warehouses: [[
                'quantity' => 10,
                'price' => 0.29,
            ],
            ]
        );
        (new CreateProductAction($productData, $user))->execute();

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: $variantSku,
            sku: $variantSku,
            warehouses: [[
                'quantity' => 10,
                'price' => 10.50,
            ],
            ]
        );
        (new CreateProductAction($productData, $user))->execute();

        $dto = GooglePlayInAppPurchaseReceipt::from($app, $company, $user, $region, $receipt);
        $order = $this->createMockedAction($dto)->execute();

        $this->assertNotNull($order->id);
        $this->assertCount(2, OrderItem::where('order_id', $order->id)->get());
    }
}
