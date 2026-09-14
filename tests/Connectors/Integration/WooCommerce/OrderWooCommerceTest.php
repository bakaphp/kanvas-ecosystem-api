<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\WooCommerce;

use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\WooCommerce\Actions\CreateOrderAction;
use Kanvas\Connectors\WooCommerce\Actions\CreateProductAction;
use Kanvas\Connectors\WooCommerce\Actions\PullOrderFromWooCommerceAction;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Souk\Orders\Models\Order;
use Tests\TestCase;

class OrderWooCommerceTest extends TestCase
{
    public function testOrderWooCommerce(): void
    {
        $product = [
                    'id' => Str::uuid(),
                    'name' => Str::random(),
                    'slug' => Str::random(),
                    'permalink' => 'http://192.168.1.241:8000/product/t-shirt-with-logo/',
                    'date_created' => '2025-01-28T04:28:36',
                    'date_created_gmt' => '2025-01-28T04:28:36',
                    'date_modified' => '2025-01-28T04:49:29',
                    'date_modified_gmt' => '2025-01-28T04:49:29',
                    'type' => 'variable',
                    'status' => 'publish',
                    'featured' => false,
                    'catalog_visibility' => 'visible',
                    'description' => "<p>Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Vestibulum tortor quam, feugiat vitae, ultricies eget, tempor sit amet, ante. Donec eu libero sit amet quam egestas semper. Aenean ultricies mi vitae est. Mauris placerat eleifend leo.</p>\n",
                    'short_description' => "<p>This is a simple product.</p>\n",
                    'sku' => Str::uuid(),
                    'price' => '20',
                    'regular_price' => '',
                    'sale_price' => '',
                    'date_on_sale_from' => null,
                    'date_on_sale_from_gmt' => null,
                    'date_on_sale_to' => null,
                    'date_on_sale_to_gmt' => null,
                    'on_sale' => false,
                    'purchasable' => true,
                    'total_sales' => 0,
                    'virtual' => false,
                    'downloadable' => false,
                    'downloads' => [],
                    'download_limit' => 0,
                    'download_expiry' => 0,
                    'external_url' => '',
                    'button_text' => '',
                    'tax_status' => 'taxable',
                    'tax_class' => '',
                    'manage_stock' => false,
                    'stock_quantity' => null,
                    'backorders' => 'no',
                    'backorders_allowed' => false,
                    'backordered' => false,
                    'low_stock_amount' => null,
                    'sold_individually' => false,
                    'weight' => '',
                    'dimensions' => [
                        'length' => '',
                        'width' => '',
                        'height' => '',
                    ],
                    'shipping_required' => true,
                    'shipping_taxable' => true,
                    'shipping_class' => '',
                    'shipping_class_id' => 0,
                    'reviews_allowed' => true,
                    'average_rating' => '0.00',
                    'rating_count' => 0,
                    'upsell_ids' => [],
                    'cross_sell_ids' => [],
                    'parent_id' => 0,
                    'purchase_note' => '',
                    'categories' => [
                        [
                            'id' => 17,
                            'name' => 'Tshirts',
                            'slug' => 'tshirts',
                        ],
                    ],
                    'tags' => [],
                    'images' => [
                        [
                            'id' => 72,
                            'date_created' => '2025-01-28T04:28:59',
                            'date_created_gmt' => '2025-01-28T04:28:59',
                            'date_modified' => '2025-01-28T04:28:59',
                            'date_modified_gmt' => '2025-01-28T04:28:59',
                            'src' => 'http://192.168.1.241:8000/wp-content/uploads/2025/01/t-shirt-with-logo-1.jpg',
                            'name' => 't-shirt-with-logo-1.jpg',
                            'alt' => '',
                        ],
                    ],
                    'attributes' => [
                        [
                            'id' => 1,
                            'name' => 'Color',
                            'slug' => 'pa_color',
                            'position' => 0,
                            'visible' => true,
                            'variation' => false,
                            'options' => ['Gray'],
                        ],
                        [
                            'id' => 2,
                            'name' => 'Size',
                            'slug' => 'pa_size',
                            'position' => 1,
                            'visible' => true,
                            'variation' => true,
                            'options' => ['Large', 'Medium'],
                        ],
                    ],
                    'default_attributes' => [],
                    'variations' => [],
                    'grouped_products' => [],
                    'menu_order' => 0,
                    'price_html' => '<span class="woocommerce-Price-amount amount"><bdi><span class="woocommerce-Price-currencySymbol">RD&#36;</span>20.00</bdi></span>',
                    'related_ids' => [25, 18, 26, 15],
                    'meta_data' => [
                        [
                            'id' => 627,
                            'key' => '_wpcom_is_markdown',
                            'value' => '1',
                        ],
                    ],
                    'stock_status' => 'instock',
                    'has_options' => true,
                    'post_password' => '',
                    'global_unique_id' => '',
                    'brands' => [],
                    '_links' => [
                        'self' => [
                            [
                                'href' => 'http://192.168.1.241:8000/wp-json/wc/v3/products/35',
                                'targetHints' => [
                                    'allow' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'],
                                ],
                            ],
                        ],
                        'collection' => [
                            [
                                'href' => 'http://192.168.1.241:8000/wp-json/wc/v3/products',
                            ],
                        ],
                    ],
                ];
        $product = json_encode($product);
        $product = json_decode($product);
        $user = auth()->user();
        $regionDto = RegionDto::from([
            'company' => $user->getCurrentCompany(),
            'app' => app(Apps::class),
            'user' => $user,
            'currency' => Currencies::getByCode('USD'),
            'name' => 'Region Test',
            'short_slug' => Str::createRandomStringsNormally() . Str::random(5),
        ]);
        $region = (new CreateRegionAction($regionDto, $user))->execute();
        $productDb = (
            new CreateProductAction(
                app(Apps::class),
                $user->getCurrentCompany(),
                $user,
                $region,
                $product
            )
        )->execute();

        $orderArray = [
                'id' => rand(1, 1000),
                'parent_id' => 0,
                'number' => rand(1, 1000),
                'order_key' => Str::random(5),
                'created_via' => 'rest-api',
                'version' => '3.0.0',
                'status' => 'processing',
                'currency' => 'USD',
                'date_created' => '2017-03-22T16:28:02',
                'date_created_gmt' => '2017-03-22T19:28:02',
                'date_modified' => '2017-03-22T16:28:08',
                'date_modified_gmt' => '2017-03-22T19:28:08',
                'discount_total' => '0.00',
                'discount_tax' => '0.00',
                'shipping_total' => '10.00',
                'shipping_tax' => '0.00',
                'cart_tax' => '1.35',
                'total' => '29.35',
                'total_tax' => '1.35',
                'prices_include_tax' => false,
                'customer_id' => 0,
                'customer_ip_address' => '',
                'customer_user_agent' => '',
                'customer_note' => '',
                'billing' => [
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'company' => '',
                    'address_1' => '969 Market',
                    'address_2' => '',
                    'city' => 'San Francisco',
                    'state' => 'CA',
                    'postcode' => '94103',
                    'country' => 'US',
                    'email' => 'john.doe@example.com',
                    'phone' => '(555) 555-5555',
                ],
                'shipping' => [
                    'first_name' => 'John',
                    'last_name' => 'Doe',
                    'company' => '',
                    'address_1' => '969 Market',
                    'address_2' => '',
                    'city' => 'San Francisco',
                    'state' => 'CA',
                    'postcode' => '94103',
                    'country' => 'US',
                ],
                'payment_method' => 'bacs',
                'payment_method_title' => 'Direct Bank Transfer',
                'transaction_id' => '',
                'date_paid' => '2017-03-22T16:28:08',
                'date_paid_gmt' => '2017-03-22T19:28:08',
                'date_completed' => null,
                'date_completed_gmt' => null,
                'cart_hash' => '',
                'meta_data' => [
                    [
                        'id' => 13106,
                        'key' => '_download_permissions_granted',
                        'value' => 'yes',
                    ],
                ],
                'line_items' => [
                    [
                        'id' => $productDb->id,
                        'name' => $productDb->name,
                        'product_id' => 93,
                        'variation_id' => 0,
                        'quantity' => 2,
                        'tax_class' => '',
                        'subtotal' => '6.00',
                        'subtotal_tax' => '0.45',
                        'total' => '6.00',
                        'total_tax' => '0.45',
                        'taxes' => [
                            [
                                'id' => 75,
                                'total' => '0.45',
                                'subtotal' => '0.45',
                            ],
                        ],
                        'meta_data' => [],
                        'sku' => $productDb->variants()->first()->sku,
                        'price' => 3,
                    ],
                ],
                'tax_lines' => [
                    [
                        'id' => 318,
                        'rate_code' => 'US-CA-STATE TAX',
                        'rate_id' => 75,
                        'label' => 'State Tax',
                        'compound' => false,
                        'tax_total' => '1.35',
                        'shipping_tax_total' => '0.00',
                        'meta_data' => [],
                    ],
                ],
                'shipping_lines' => [
                    [
                        'id' => 317,
                        'method_title' => 'Flat Rate',
                        'method_id' => 'flat_rate',
                        'total' => '10.00',
                        'total_tax' => '0.00',
                        'taxes' => [],
                        'meta_data' => [],
                    ],
                ],
                'fee_lines' => [],
                'coupon_lines' => [],
                'refunds' => [],
                '_links' => [
                    'self' => [
                        [
                            'href' => 'https://example.com/wp-json/wc/v3/orders/727',
                        ],
                    ],
                    'collection' => [
                        [
                            'href' => 'https://example.com/wp-json/wc/v3/orders',
                        ],
                    ],
                ],
        ];
        $order = json_encode($orderArray);
        $order = json_decode($order);
        $orderDB = (
              new CreateOrderAction(
                  app(Apps::class),
                  $user->getCurrentCompany(),
                  $user,
                  $region,
                  $order
              )
          )->execute();
        $this->assertInstanceOf(Order::class, $orderDB);
    }

    public function testDiscountedOrderStoresPaidTotalAndMetadata(): void
    {
        $user = auth()->user();
        $regionDto = RegionDto::from([
            'company' => $user->getCurrentCompany(),
            'app' => app(Apps::class),
            'user' => $user,
            'currency' => Currencies::getByCode('USD'),
            'name' => 'Region Test',
            'short_slug' => Str::createRandomStringsNormally() . Str::random(5),
        ]);
        $region = (new CreateRegionAction($regionDto, $user))->execute();

        $product = json_decode(json_encode([
            'id' => Str::uuid(),
            'name' => Str::random(),
            'slug' => Str::random(),
            'type' => 'simple',
            'status' => 'publish',
            'sku' => Str::uuid(),
            'price' => '6',
            'regular_price' => '6',
            'stock_quantity' => null,
            'description' => '',
            'short_description' => '',
            'categories' => [],
            'images' => [],
            'attributes' => [],
            'variations' => [],
            'meta_data' => [],
        ]));
        $productDb = (
            new CreateProductAction(
                app(Apps::class),
                $user->getCurrentCompany(),
                $user,
                $region,
                $product
            )
        )->execute();

        $billingEmail = 'dairon+' . Str::random(5) . '@example.com';

        // subtotal 6.00 - discount 1.20 = 4.80 actually charged (no tax, no shipping)
        $order = json_decode(json_encode([
            'id' => rand(1, 100000),
            'number' => rand(1, 100000),
            'order_key' => Str::random(5),
            'status' => 'completed',
            'currency' => 'USD',
            'discount_total' => '1.20',
            'shipping_total' => '0.00',
            'total' => '4.80',
            'total_tax' => '0.00',
            'payment_method' => 'wallet',
            'payment_method_title' => 'Pago mediante Mi Wallet',
            'billing' => [
                'first_name' => 'Dairon',
                'last_name' => 'Amador',
                'company' => '',
                'address_1' => '',
                'address_2' => '',
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => 'US',
                'email' => $billingEmail,
                'phone' => '8094913594',
            ],
            'shipping' => [
                'first_name' => '',
                'last_name' => '',
                'company' => '',
                'address_1' => '',
                'address_2' => '',
                'city' => '',
                'state' => '',
                'postcode' => '',
                'country' => '',
            ],
            'meta_data' => [
                ['id' => 1, 'key' => 'purchase_type', 'value' => 'new'],
                ['id' => 2, 'key' => '_ga_tracked', 'value' => '1'],
                ['id' => 3, 'key' => 'trp_language', 'value' => 'es_ES'],
            ],
            'line_items' => [
                [
                    'id' => $productDb->id,
                    'name' => $productDb->name,
                    'product_id' => 473,
                    'variation_id' => 0,
                    'quantity' => 1,
                    'subtotal' => '6.00',
                    'subtotal_tax' => '0.00',
                    'total' => '4.80',
                    'total_tax' => '0.00',
                    'sku' => $productDb->variants()->first()->sku,
                    'price' => 4.80,
                    'meta_data' => [],
                ],
            ],
            'tax_lines' => [],
            'shipping_lines' => [],
            'fee_lines' => [],
        ]));

        $orderDB = (
            new CreateOrderAction(
                app(Apps::class),
                $user->getCurrentCompany(),
                $user,
                $region,
                $order
            )
        )->execute();

        // total_gross_amount must equal the line-item subtotal actually charged (4.80), not the
        // pre-discount 6.00 nor a double-counted total — this is what the affiliate commission uses.
        $this->assertEquals(4.80, (float) $orderDB->total_gross_amount);
        $this->assertEquals(1.20, (float) $orderDB->discount_amount);
        $this->assertEquals('new', $orderDB->metadata['woocommerce_meta_data']['purchase_type']);
        $this->assertEquals('1', $orderDB->metadata['woocommerce_meta_data']['_ga_tracked']);

        // First-class order fields mapped from the WooCommerce payload.
        $this->assertEquals($billingEmail, $orderDB->user_email);
        $this->assertEquals('8094913594', $orderDB->user_phone);
        $this->assertContains('Pago mediante Mi Wallet', (array) $orderDB->payment_gateway_names);
        $this->assertEquals('es', $orderDB->language_code);
    }

    public function testResolveOrderNumberMintsNonCollidingNumberOnlyOnCollision(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        [$region, $productDb] = $this->createRegionAndProduct();

        $existingNumber = (string) rand(1, 100000);

        // Seed an order that already owns $existingNumber for this app/company.
        (new CreateOrderAction(
            $app,
            $company,
            $user,
            $region,
            $this->buildWooOrderPayload($productDb, rand(1, 100000), $existingNumber)
        ))->execute();

        // Collision → mint the next number in the sequence: numeric (fits the bigint column), distinct
        // from the colliding number, and itself free of collisions.
        $resolved = PullOrderFromWooCommerceAction::resolveOrderNumber($app, $company, $existingNumber);
        $this->assertTrue(ctype_digit($resolved), 'Minted order number must be a plain integer');
        $this->assertNotEquals($existingNumber, $resolved);
        $this->assertGreaterThan((int) $existingNumber, (int) $resolved);
        $this->assertFalse(
            Order::where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->where('order_number', $resolved)
                ->exists(),
            'Minted order number must not collide with an existing order'
        );

        // No collision → the WooCommerce number is used as-is. Pick a number safely above the current
        // max so it is guaranteed free regardless of other rows in the shared test DB.
        $maxNow = (int) Order::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->withTrashed()
            ->max('order_number');
        $freshNumber = (string) ($maxNow + 987654);
        $this->assertEquals(
            $freshNumber,
            PullOrderFromWooCommerceAction::resolveOrderNumber($app, $company, $freshNumber)
        );
    }

    /**
     * End-to-end guard for the collision fix: two WooCommerce orders sharing a number must both
     * persist, and the second's minted number must survive the bigint column intact (not be truncated
     * back into a collision — the failure mode that a return-value-only assertion would miss).
     */
    public function testCollidingPulledOrderPersistsMintedNumberThroughBigintColumn(): void
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        [$region, $productDb] = $this->createRegionAndProduct();

        $sharedNumber = (string) rand(1, 100000);

        $firstOrder = (new CreateOrderAction(
            $app,
            $company,
            $user,
            $region,
            $this->buildWooOrderPayload($productDb, rand(1, 100000), $sharedNumber)
        ))->execute();

        // Simulate the pull deciding the order_number for the second (different) WooCommerce order.
        $mintedNumber = PullOrderFromWooCommerceAction::resolveOrderNumber($app, $company, $sharedNumber);

        $secondOrder = (new CreateOrderAction(
            $app,
            $company,
            $user,
            $region,
            $this->buildWooOrderPayload($productDb, rand(100001, 200000), $sharedNumber),
            orderNumberOverride: $mintedNumber
        ))->execute();

        // Reload from the DB so we assert what actually round-tripped through the bigint column.
        $persisted = Order::getById($secondOrder->getKey(), $app);

        $this->assertNotEquals($firstOrder->getKey(), $persisted->getKey());
        $this->assertEquals((int) $mintedNumber, (int) $persisted->order_number);
        $this->assertNotEquals((int) $sharedNumber, (int) $persisted->order_number);
        $this->assertEquals((int) $sharedNumber, (int) $firstOrder->order_number);
    }

    /**
     * @return array{0: \Kanvas\Regions\Models\Regions, 1: \Kanvas\Souk\Orders\Models\Order|mixed}
     */
    private function createRegionAndProduct(): array
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $regionDto = RegionDto::from([
            'company' => $company,
            'app' => $app,
            'user' => $user,
            'currency' => Currencies::getByCode('USD'),
            'name' => 'Region Test',
            'short_slug' => Str::createRandomStringsNormally() . Str::random(5),
        ]);
        $region = (new CreateRegionAction($regionDto, $user))->execute();

        $product = json_decode(json_encode([
            'id' => Str::uuid(),
            'name' => Str::random(),
            'slug' => Str::random(),
            'type' => 'simple',
            'status' => 'publish',
            'sku' => Str::uuid(),
            'price' => '6',
            'regular_price' => '6',
            'stock_quantity' => null,
            'description' => '',
            'short_description' => '',
            'categories' => [],
            'images' => [],
            'attributes' => [],
            'variations' => [],
            'meta_data' => [],
        ]));
        $productDb = (new CreateProductAction($app, $company, $user, $region, $product))->execute();

        return [$region, $productDb];
    }

    private function buildWooOrderPayload(object $productDb, int $wooId, string $number): object
    {
        return json_decode(json_encode([
            'id' => $wooId,
            'number' => $number,
            'order_key' => Str::random(5),
            'status' => 'completed',
            'currency' => 'USD',
            'discount_total' => '0.00',
            'shipping_total' => '0.00',
            'total' => '6.00',
            'total_tax' => '0.00',
            'payment_method' => 'bacs',
            'payment_method_title' => 'Direct Bank Transfer',
            'billing' => [
                'first_name' => 'John', 'last_name' => 'Doe', 'company' => '',
                'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '',
                'postcode' => '', 'country' => 'US',
                'email' => 'buyer+' . Str::random(5) . '@example.com', 'phone' => '8090000000',
            ],
            'shipping' => [
                'first_name' => '', 'last_name' => '', 'company' => '',
                'address_1' => '', 'address_2' => '', 'city' => '', 'state' => '',
                'postcode' => '', 'country' => '',
            ],
            'meta_data' => [],
            'line_items' => [[
                'id' => $productDb->id, 'name' => $productDb->name, 'product_id' => 1,
                'variation_id' => 0, 'quantity' => 1, 'subtotal' => '6.00', 'subtotal_tax' => '0.00',
                'total' => '6.00', 'total_tax' => '0.00',
                'sku' => $productDb->variants()->first()->sku, 'price' => 6.0, 'meta_data' => [],
            ]],
            'tax_lines' => [], 'shipping_lines' => [], 'fee_lines' => [],
        ]));
    }
}
