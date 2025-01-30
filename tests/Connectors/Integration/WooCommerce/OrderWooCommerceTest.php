<?php
declare(strict_types=1);

namespace Tests\Connectors\Integration\WooCommerce;

use Kanvas\Connectors\WooCommerce\Actions\CreateProductAction;
use Tests\TestCase;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Connectors\WooCommerce\Actions\CreateOrderAction;
use Kanvas\Souk\Orders\Models\Order;

class OrderWooCommerceTest extends TestCase
{
    public function testOrderWooCommerce(): void
    {
        $product = [
                    "id" => Str::uuid(),
                    "name" => Str::random(),
                    "slug" => Str::random(),
                    "permalink" => "http://192.168.1.241:8000/product/t-shirt-with-logo/",
                    "date_created" => "2025-01-28T04:28:36",
                    "date_created_gmt" => "2025-01-28T04:28:36",
                    "date_modified" => "2025-01-28T04:49:29",
                    "date_modified_gmt" => "2025-01-28T04:49:29",
                    "type" => "variable",
                    "status" => "publish",
                    "featured" => false,
                    "catalog_visibility" => "visible",
                    "description" => "<p>Pellentesque habitant morbi tristique senectus et netus et malesuada fames ac turpis egestas. Vestibulum tortor quam, feugiat vitae, ultricies eget, tempor sit amet, ante. Donec eu libero sit amet quam egestas semper. Aenean ultricies mi vitae est. Mauris placerat eleifend leo.</p>\n",
                    "short_description" => "<p>This is a simple product.</p>\n",
                    "sku" => Str::uuid(),
                    "price" => "20",
                    "regular_price" => "",
                    "sale_price" => "",
                    "date_on_sale_from" => null,
                    "date_on_sale_from_gmt" => null,
                    "date_on_sale_to" => null,
                    "date_on_sale_to_gmt" => null,
                    "on_sale" => false,
                    "purchasable" => true,
                    "total_sales" => 0,
                    "virtual" => false,
                    "downloadable" => false,
                    "downloads" => [],
                    "download_limit" => 0,
                    "download_expiry" => 0,
                    "external_url" => "",
                    "button_text" => "",
                    "tax_status" => "taxable",
                    "tax_class" => "",
                    "manage_stock" => false,
                    "stock_quantity" => null,
                    "backorders" => "no",
                    "backorders_allowed" => false,
                    "backordered" => false,
                    "low_stock_amount" => null,
                    "sold_individually" => false,
                    "weight" => "",
                    "dimensions" => [
                        "length" => "",
                        "width" => "",
                        "height" => ""
                    ],
                    "shipping_required" => true,
                    "shipping_taxable" => true,
                    "shipping_class" => "",
                    "shipping_class_id" => 0,
                    "reviews_allowed" => true,
                    "average_rating" => "0.00",
                    "rating_count" => 0,
                    "upsell_ids" => [],
                    "cross_sell_ids" => [],
                    "parent_id" => 0,
                    "purchase_note" => "",
                    "categories" => [
                        [
                            "id" => 17,
                            "name" => "Tshirts",
                            "slug" => "tshirts"
                        ]
                    ],
                    "tags" => [],
                    "images" => [
                        [
                            "id" => 72,
                            "date_created" => "2025-01-28T04:28:59",
                            "date_created_gmt" => "2025-01-28T04:28:59",
                            "date_modified" => "2025-01-28T04:28:59",
                            "date_modified_gmt" => "2025-01-28T04:28:59",
                            "src" => "http://192.168.1.241:8000/wp-content/uploads/2025/01/t-shirt-with-logo-1.jpg",
                            "name" => "t-shirt-with-logo-1.jpg",
                            "alt" => ""
                        ]
                    ],
                    "attributes" => [
                        [
                            "id" => 1,
                            "name" => "Color",
                            "slug" => "pa_color",
                            "position" => 0,
                            "visible" => true,
                            "variation" => false,
                            "options" => ["Gray"]
                        ],
                        [
                            "id" => 2,
                            "name" => "Size",
                            "slug" => "pa_size",
                            "position" => 1,
                            "visible" => true,
                            "variation" => true,
                            "options" => ["Large", "Medium"]
                        ]
                    ],
                    "default_attributes" => [],
                    "variations" => [],
                    "grouped_products" => [],
                    "menu_order" => 0,
                    "price_html" => "<span class=\"woocommerce-Price-amount amount\"><bdi><span class=\"woocommerce-Price-currencySymbol\">RD&#36;</span>20.00</bdi></span>",
                    "related_ids" => [25, 18, 26, 15],
                    "meta_data" => [
                        [
                            "id" => 627,
                            "key" => "_wpcom_is_markdown",
                            "value" => "1"
                        ]
                    ],
                    "stock_status" => "instock",
                    "has_options" => true,
                    "post_password" => "",
                    "global_unique_id" => "",
                    "brands" => [],
                    "_links" => [
                        "self" => [
                            [
                                "href" => "http://192.168.1.241:8000/wp-json/wc/v3/products/35",
                                "targetHints" => [
                                    "allow" => ["GET", "POST", "PUT", "PATCH", "DELETE"]
                                ]
                            ]
                        ],
                        "collection" => [
                            [
                                "href" => "http://192.168.1.241:8000/wp-json/wc/v3/products"
                            ]
                        ]
                    ]
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
            'short_slug' => Str::createRandomStringsNormally(). Str::random(5)
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
                "id" => rand(1, 1000),
                "parent_id" => 0,
                "number" => rand(1, 1000),
                "order_key" => Str::random(5),
                "created_via" => "rest-api",
                "version" => "3.0.0",
                "status" => "processing",
                "currency" => "USD",
                "date_created" => "2017-03-22T16:28:02",
                "date_created_gmt" => "2017-03-22T19:28:02",
                "date_modified" => "2017-03-22T16:28:08",
                "date_modified_gmt" => "2017-03-22T19:28:08",
                "discount_total" => "0.00",
                "discount_tax" => "0.00",
                "shipping_total" => "10.00",
                "shipping_tax" => "0.00",
                "cart_tax" => "1.35",
                "total" => "29.35",
                "total_tax" => "1.35",
                "prices_include_tax" => false,
                "customer_id" => 0,
                "customer_ip_address" => "",
                "customer_user_agent" => "",
                "customer_note" => "",
                "billing" => [
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
                "shipping" => [
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
                "date_paid" => "2017-03-22T16:28:08",
                "date_paid_gmt" => "2017-03-22T19:28:08",
                "date_completed" => null,
                "date_completed_gmt" => null,
                "cart_hash" => "",
                "meta_data" => [
                    [
                        "id" => 13106,
                        "key" => "_download_permissions_granted",
                        "value" => "yes"
                    ]
                ],
                "line_items" => [
                    [
                        "id" => $productDb->id,
                        "name" => $productDb->name,
                        "product_id" => 93,
                        "variation_id" => 0,
                        "quantity" => 2,
                        "tax_class" => "",
                        "subtotal" => "6.00",
                        "subtotal_tax" => "0.45",
                        "total" => "6.00",
                        "total_tax" => "0.45",
                        "taxes" => [
                            [
                                "id" => 75,
                                "total" => "0.45",
                                "subtotal" => "0.45"
                            ]
                        ],
                        "meta_data" => [],
                        "sku" => $productDb->variants()->first()->sku,
                        "price" => 3
                    ],
                ],
                "tax_lines" => [
                    [
                        "id" => 318,
                        "rate_code" => "US-CA-STATE TAX",
                        "rate_id" => 75,
                        "label" => "State Tax",
                        "compound" => false,
                        "tax_total" => "1.35",
                        "shipping_tax_total" => "0.00",
                        "meta_data" => []
                    ]
                ],
                "shipping_lines" => [
                    [
                        "id" => 317,
                        "method_title" => "Flat Rate",
                        "method_id" => "flat_rate",
                        "total" => "10.00",
                        "total_tax" => "0.00",
                        "taxes" => [],
                        "meta_data" => []
                    ]
                ],
                "fee_lines" => [],
                "coupon_lines" => [],
                "refunds" => [],
                "_links" => [
                    "self" => [
                        [
                            "href" => "https://example.com/wp-json/wc/v3/orders/727"
                        ]
                    ],
                    "collection" => [
                        [
                            "href" => "https://example.com/wp-json/wc/v3/orders"
                        ]
                    ]
                ]
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
}
