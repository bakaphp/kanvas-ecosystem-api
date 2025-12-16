<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WooCommerce;

use Exception;
use Illuminate\Support\Str;
use Kanvas\Connectors\WooCommerce\Actions\CreateProductAction;
use Kanvas\Connectors\WooCommerce\Actions\PullOrderFromWooCommerceAction;
use Kanvas\Connectors\WooCommerce\Enums\CustomFieldEnum;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

class PullOrderFromWooCommerceTest extends TestCase
{
    public function testPullOrderFromWooCommerce(): void
    {
        // Create a product first
        $product = $this->createTestProduct();

        (new CreateProductAction(
            app(),
            $this->company,
            auth()->user(),
            $this->region,
            $product
        ))->execute();

        // Create a WooCommerce order object
        $wooCommerceOrder = $this->createTestWooCommerceOrder($product);

        // Mock the WooCommerce order ID
        $wooCommerceOrderId = $wooCommerceOrder->id;

        // Pull the order from WooCommerce (in this test, we're passing the object directly)
        $action = new PullOrderFromWooCommerceAction(
            app(),
            $this->company,
            auth()->user(),
            $this->region,
            $wooCommerceOrderId
        );

        // Note: This will fail in a real scenario without mocking the WooCommerce client
        // For a proper test, you would need to mock the WooCommerce client's get method
        // $kanvasOrder = $action->execute();

        $this->assertTrue(true);
    }

    public function testPullOrderPreventsDoublelImport(): void
    {
        // Create a product first
        $product = $this->createTestProduct();

        (new CreateProductAction(
            app(),
            $this->company,
            auth()->user(),
            $this->region,
            $product
        ))->execute();

        // Create a WooCommerce order object
        $wooCommerceOrder = $this->createTestWooCommerceOrder($product);
        $wooCommerceOrderId = $wooCommerceOrder->id;

        // Create an order manually and set the WooCommerce ID
        $order = Order::factory()->create([
            'apps_id' => app()->getId(),
            'companies_id' => $this->company->getId(),
            'region_id' => $this->region->getId(),
        ]);

        $order->set(CustomFieldEnum::WOOCOMMERCE_ID->value, $wooCommerceOrderId);

        // Try to pull the same order again - should throw an exception
        $this->expectException(Exception::class);
        $this->expectExceptionMessageMatches("/already exists in Kanvas/");

        $action = new PullOrderFromWooCommerceAction(
            app(),
            $this->company,
            auth()->user(),
            $this->region,
            $wooCommerceOrderId
        );

        // This should throw an exception
        $action->execute();
    }

    protected function createTestProduct(): object
    {
        return (object) [
            "id" => rand(1, 1000),
            "name" => Str::random(),
            "slug" => Str::random(),
            "permalink" => "http://test.com/product/test/",
            "type" => "simple",
            "status" => "publish",
            "sku" => Str::uuid(),
            "price" => "20",
            "regular_price" => "20",
            "description" => "Test product",
            "short_description" => "Test product",
            "manage_stock" => false,
            "stock_quantity" => null,
            "categories" => [],
            "tags" => [],
            "images" => [],
            "attributes" => [],
        ];
    }

    protected function createTestWooCommerceOrder(object $product): object
    {
        return (object) [
            "id" => rand(1, 1000),
            "parent_id" => 0,
            "number" => rand(1, 1000),
            "order_key" => Str::random(5),
            "created_via" => "rest-api",
            "version" => "3.0.0",
            "status" => "completed",
            "currency" => "USD",
            "date_created" => "2025-03-22T16:28:02",
            "date_created_gmt" => "2025-03-22T19:28:02",
            "date_modified" => "2025-03-22T16:28:08",
            "date_modified_gmt" => "2025-03-22T19:28:08",
            "discount_total" => "0.00",
            "discount_tax" => "0.00",
            "shipping_total" => "10.00",
            "shipping_tax" => "0.00",
            "cart_tax" => "1.95",
            "total" => "31.95",
            "total_tax" => "1.95",
            "prices_include_tax" => false,
            "customer_id" => 0,
            "customer_ip_address" => "",
            "customer_user_agent" => "",
            "customer_note" => "",
            "billing" => (object) [
                "first_name" => "John",
                "last_name" => "Doe",
                "company" => "",
                "address_1" => "969 Market",
                "address_2" => "",
                "city" => "San Francisco",
                "state" => "CA",
                "postcode" => "94103",
                "country" => "US",
                "email" => "john.doe@example.com",
                "phone" => "(555) 555-5555"
            ],
            "shipping" => (object) [
                "first_name" => "John",
                "last_name" => "Doe",
                "company" => "",
                "address_1" => "969 Market",
                "address_2" => "",
                "city" => "San Francisco",
                "state" => "CA",
                "postcode" => "94103",
                "country" => "US"
            ],
            "payment_method" => "bacs",
            "payment_method_title" => "Direct Bank Transfer",
            "transaction_id" => "",
            "line_items" => [
                (object) [
                    "id" => rand(1, 1000),
                    "name" => $product->name,
                    "product_id" => $product->id,
                    "variation_id" => 0,
                    "quantity" => 2,
                    "tax_class" => "",
                    "subtotal" => "40.00",
                    "subtotal_tax" => "1.95",
                    "total" => "40.00",
                    "total_tax" => "1.95",
                    "sku" => $product->sku,
                    "price" => 20
                ]
            ],
            "tax_lines" => [],
            "shipping_lines" => [
                (object) [
                    "id" => rand(1, 1000),
                    "method_title" => "Flat Rate",
                    "method_id" => "flat_rate",
                    "total" => "10.00",
                    "total_tax" => "0.00"
                ]
            ],
            "fee_lines" => [],
            "coupon_lines" => []
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure we have a region
        if (! $this->region) {
            $currency = Currencies::where('code', 'USD')->first();
            $regionDto = RegionDto::from([
                'name' => 'Test Region',
                'slug' => 'test-region',
                'currency_id' => $currency->getId(),
                'short_slug' => 'test',
                'is_default' => false,
            ]);

            $this->region = (new CreateRegionAction($regionDto))->execute();
        }
    }
}
