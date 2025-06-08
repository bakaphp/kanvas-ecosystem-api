<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Variants\Models\VariantsWarehouses;
use Kanvas\Inventory\Warehouses\Models\Warehouses;
use Kanvas\Souk\Enums\ConfigurationEnum;
use Kanvas\Souk\Orders\Models\Order;
use Tests\GraphQL\Inventory\Traits\InventoryCases;
use Tests\TestCase;

class OrderWalletTest extends TestCase
{
    use InventoryCases;

    public function testCreateOrderFromWallet()
    {
        //$variantWarehouse = VariantsWarehouses::first();
        //$region = $variantWarehouse->warehouse->region;
        //$app = $region->app;
        $user = auth()->user();
        $company = $user->getCurrentCompany();
        $app = app(Apps::class);
        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: fake()->name(),
            sku: fake()->unique()->word(),
            warehouses: [[
                'quantity' => 10,
                'price' => 0.29,
            ],
            ]
        );
        $product = (new CreateProductAction($productData, $user))->execute();
        $variant = $product->variants()->first();
        $warehouse = Warehouses::fromApp($app)->fromCompany($company)->first();
        $channel = Channels::fromApp($app)->fromCompany($company)->first();
        $variant->updatePriceInWarehouse($warehouse, 100);
        $variant->updatePriceInChannel($channel, 100);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_NOTIFICATION->value);
        $app->del(ConfigurationEnum::SEND_NEW_ORDER_TO_OWNER_NOTIFICATION->value);
        //$company->associateUserApp($user, $app, 1);
        // Prepare input data for the order

        $wallet = $company->createAppWallet($app, ['name' => 'default'])->deposit(100000, [
            'description' => 'Initial deposit for order testing',
            'slug' => 'initial-deposit',
        ]);

        $data = [
            'cartId' => 'default',

            'customer' => [
                'email' => $user->email,
                'phone' => $user->phone,
            ],
            'items' => [
                [
                    'variant_id' => $variant->getId(),
                    'quantity' => 2,
                ],
            ],
            'shipping_address' => [
                'address' => fake()->address(),
                'address_2' => fake()->postcode(),
                'city' => fake()->city(),
                'state' => fake()->state(),
            ],
            'metadata' => [
                'user_company_id' => $company->getId(),
            ],
        ];

        // Perform GraphQL mutation to create a draft order
        $response = $this->graphQL('
            mutation createOrderFromWalletCart($input: OrderCartInput!) {
                createOrderFromWalletCart(input: $input) {
                    order {
                        id
                    }
                }
            }
        ', [
            'input' => $data,
        ]);

        $response->assertJson([
             'data' => [
                 'createOrderFromWalletCart' => [
                     'order' => [
                         'id' => true, // Check if the order ID is returned
                     ],
                 ],
             ],
         ]);

        // Test the getWalletBalance query
        $balanceResponse = $this->graphQL('
            query getWalletBalance($tag: String!) {
            getWalletBalance(tag: $tag) {
                balance
                message
                data
            }
            }
        ', [
            'tag' => 'default',
        ]);

        $balanceResponse->assertJsonStructure([
            'data' => [
            'getWalletBalance' => [
                'balance',
                'message',
                'data',
            ],
            ],
        ]);
    }
}
