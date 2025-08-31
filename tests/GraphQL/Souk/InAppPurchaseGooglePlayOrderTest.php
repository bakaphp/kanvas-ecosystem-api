<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Illuminate\Support\Facades\Auth;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Google\Enums\ConfigurationEnum as EnumsConfigurationEnum;
use Kanvas\Connectors\InAppPurchase\Enums\ConfigurationEnum;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product;
use Kanvas\Inventory\Support\Setup;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\OrderItem;
use Tests\TestCase;

class InAppPurchaseGooglePlayOrderTest extends TestCase
{
    public function testCreateOrderFromGooglePlayInAppPurchase()
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();
        $app->set(ConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value, env('TEST_GOOGLE_PACKAGE_NAME'));
        $app->set(EnumsConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value, env('TEST_GOOGLE_PAYMENT_CLIENT_CONFIG'));

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        // Prepare input data for the mutation
        $receipt = [
            'product_id' => 'premium_prompt_10',
            'order_id' => 'GPA.3368-8891-7367-12318',
            'purchase_token' => 'gjackfjnplcldkklleehomik.AO-J1Ow_K_tSZouGjnWM3XXWerl6Mpp8rSx9rcE1YVayS7L1asEHkWvS_i0-V0UrzsqzHhHsogmeBTYHt2WOGJMkOR7E2c5Rdw',
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
        $product = (new CreateProductAction($productData, $user))->execute();

        // Perform GraphQL mutation
        $response = $this->graphQL('
            mutation createOrderFromGooglePlayInAppPurchase($input: GooglePlayInAppPurchaseReceipt!) {
                createOrderFromGooglePlayInAppPurchase(input: $input) {
                    id
                    uuid
                    user_email
                    total_gross_amount
                    currency
                    status
                    created_at
                }
            }
        ', [
            'input' => $receipt,
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'createOrderFromGooglePlayInAppPurchase' => [
                    'id',
                    'uuid',
                    'user_email',
                    'total_gross_amount',
                    'currency',
                    'status',
                    'created_at',
                ],
            ],
        ]);
    }

    public function testCreateOrderFromGooglePlayInAppPurchaseWithCustomFields()
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();
        $app->set(ConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value, env('TEST_GOOGLE_PACKAGE_NAME'));
        $app->set(EnumsConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value, env('TEST_GOOGLE_PAYMENT_CLIENT_CONFIG'));

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        // Prepare input data for the mutation
        $receipt = [
            'product_id' => 'premium_prompt_10',
            'order_id' => 'GPA.3368-8891-7367-12318',
            'purchase_token' => 'gjackfjnplcldkklleehomik.AO-J1Ow_K_tSZouGjnWM3XXWerl6Mpp8rSx9rcE1YVayS7L1asEHkWvS_i0-V0UrzsqzHhHsogmeBTYHt2WOGJMkOR7E2c5Rdw',
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
        $product = (new CreateProductAction($productData, $user))->execute();

        // Perform GraphQL mutation
        $response = $this->graphQL('
            mutation createOrderFromGooglePlayInAppPurchase($input: GooglePlayInAppPurchaseReceipt!) {
                createOrderFromGooglePlayInAppPurchase(input: $input) {
                    id
                    uuid
                    user_email
                    total_gross_amount
                    currency
                    status
                    created_at
                    custom_fields {
                        data {
                            name
                            value
                        }
                    }
                }
            }
        ', [
            'input' => $receipt,
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'createOrderFromGooglePlayInAppPurchase' => [
                    'id',
                    'uuid',
                    'user_email',
                    'total_gross_amount',
                    'currency',
                    'status',
                    'created_at',
                ],
            ],
        ]);
    }

    public function testCreateOrderFromGooglePlayInAppPurchaseWithCustomFieldsVariants()
    {
        $app = app(Apps::class);
        $user = Auth::user();
        $company = $user->getCurrentCompany();
        $setupInventory = new Setup($app, $user, $company);
        $setupInventory->run();
        $app->set(ConfigurationEnum::GOOGLE_PLAY_PACKAGE_NAME->value, env('TEST_GOOGLE_PACKAGE_NAME'));
        $app->set(EnumsConfigurationEnum::GOOGLE_PAYMENT_CLIENT_CONFIG->value, env('TEST_GOOGLE_PAYMENT_CLIENT_CONFIG'));

        $region = Regions::fromApp($app)->fromCompany($company)->first();

        // Prepare input data for the mutation
        $receipt = [
            'product_id' => 'premium_prompt_10',
            'order_id' => 'GPA.3368-8891-7367-12318',
            'purchase_token' => 'gjackfjnplcldkklleehomik.AO-J1Ow_K_tSZouGjnWM3XXWerl6Mpp8rSx9rcE1YVayS7L1asEHkWvS_i0-V0UrzsqzHhHsogmeBTYHt2WOGJMkOR7E2c5Rdw',
            'custom_fields' => [
                [
                    'name' => 'message_id',
                    'value' => '48843',
                ],
                [
                    'name' => 'variants_skus',
                    'value' => [
                        ['sku' => 'variant_2223', 'quantity' => 2],
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
        $product = (new CreateProductAction($productData, $user))->execute();

        $productData = new Product(
            app: $app,
            company: $company,
            user: $user,
            name: 'variant_2223',
            sku: 'variant_2223',
            warehouses: [[
                'quantity' => 10,
                'price' => 10.50,
            ],
            ]
        );
        $product = (new CreateProductAction($productData, $user))->execute();

        // Perform GraphQL mutation
        $response = $this->graphQL('
            mutation createOrderFromGooglePlayInAppPurchase($input: GooglePlayInAppPurchaseReceipt!) {
                createOrderFromGooglePlayInAppPurchase(input: $input) {
                    id
                    uuid
                    user_email
                    total_gross_amount
                    currency
                    status
                    created_at
                    custom_fields {
                        data {
                            name
                            value
                        }
                    }
                }
            }
        ', [
            'input' => $receipt,
        ]);

        $response->assertSuccessful();
        $response->assertJsonStructure([
            'data' => [
                'createOrderFromGooglePlayInAppPurchase' => [
                    'id',
                    'uuid',
                    'user_email',
                    'total_gross_amount',
                    'currency',
                    'status',
                    'created_at',
                ],
            ],
        ]);

        $this->assertCount(2, OrderItem::where('order_id', $response->json('data.createOrderFromGooglePlayInAppPurchase.id'))->get());
    }
}
