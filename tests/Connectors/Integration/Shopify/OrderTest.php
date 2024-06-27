<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\SyncShopifyOrderAction;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Souk\Orders\Models\Order;
use Tests\Connectors\Traits\HasShopifyConfiguration;
use Tests\TestCase;

final class OrderTest extends TestCase
{
    use HasShopifyConfiguration;

    public function testOrderWebhook()
    {
        $app = app(Apps::class);
        $product = Products::first();
        $channel = Channels::fromCompany($product->company)->first();
        $variant = $product->variants()->first();
        $warehouse = $variant->warehouses()->first();
        $this->setupShopifyConfiguration($product, $warehouse);
        $region = $warehouse->region;

        $shopify = new ShopifyInventoryService(
            $product->app,
            $product->company,
            $warehouse
        );

        $shopifyOrderData = json_decode('{"id":820982911946154508,"admin_graphql_api_id":"gid://shopify/Order/820982911946154508","app_id":null,"browser_ip":null,"buyer_accepts_marketing":true,"cancel_reason":"customer","cancelled_at":"2024-06-26T12:33:21-04:00","cart_token":null,"checkout_id":null,"checkout_token":null,"client_details":null,"closed_at":null,"company":null,"confirmation_number":null,"confirmed":false,"contact_email":"jon@example.com","created_at":"2024-06-26T12:33:21-04:00","currency":"USD","current_subtotal_price":"1585.90","current_subtotal_price_set":{"shop_money":{"amount":"1585.90","currency_code":"USD"},"presentment_money":{"amount":"1585.90","currency_code":"USD"}},"current_total_additional_fees_set":null,"current_total_discounts":"0.00","current_total_discounts_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"current_total_duties_set":null,"current_total_price":"1585.90","current_total_price_set":{"shop_money":{"amount":"1585.90","currency_code":"USD"},"presentment_money":{"amount":"1585.90","currency_code":"USD"}},"current_total_tax":"0.00","current_total_tax_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"customer_locale":"en","device_id":null,"discount_codes":[],"duties_included":false,"email":"jon@example.com","estimated_taxes":false,"financial_status":"voided","fulfillment_status":"pending","landing_site":null,"landing_site_ref":null,"location_id":null,"merchant_of_record_app_id":null,"name":"#9999","note":null,"note_attributes":[],"number":234,"order_number":1234,"order_status_url":"https://devkanvas.myshopify.com/87296377148/orders/123456abcd/authenticate?key=abcdefg","original_total_additional_fees_set":null,"original_total_duties_set":null,"payment_gateway_names":["visa","bogus"],"phone":null,"po_number":null,"presentment_currency":"USD","processed_at":"2024-06-26T12:33:21-04:00","reference":null,"referring_site":null,"source_identifier":null,"source_name":"web","source_url":null,"subtotal_price":"1575.90","subtotal_price_set":{"shop_money":{"amount":"1575.90","currency_code":"USD"},"presentment_money":{"amount":"1575.90","currency_code":"USD"}},"tags":"tag1, tag2","tax_exempt":false,"tax_lines":[],"taxes_included":false,"test":true,"token":"123456abcd","total_discounts":"20.00","total_discounts_set":{"shop_money":{"amount":"20.00","currency_code":"USD"},"presentment_money":{"amount":"20.00","currency_code":"USD"}},"total_line_items_price":"1585.90","total_line_items_price_set":{"shop_money":{"amount":"1585.90","currency_code":"USD"},"presentment_money":{"amount":"1585.90","currency_code":"USD"}},"total_outstanding":"1585.90","total_price":"1575.90","total_price_set":{"shop_money":{"amount":"1575.90","currency_code":"USD"},"presentment_money":{"amount":"1575.90","currency_code":"USD"}},"total_shipping_price_set":{"shop_money":{"amount":"10.00","currency_code":"USD"},"presentment_money":{"amount":"10.00","currency_code":"USD"}},"total_tax":"0.00","total_tax_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"total_tip_received":"0.00","total_weight":0,"updated_at":"2024-06-26T12:33:21-04:00","user_id":null,"billing_address":{"first_name":"Steve","address1":"123 Shipping Street","phone":"555-555-SHIP","city":"Shippington","zip":"40003","province":"Kentucky","country":"United States","last_name":"Shipper","address2":null,"company":"Shipping Company","latitude":null,"longitude":null,"name":"Steve Shipper","country_code":"US","province_code":"KY"},"customer":{"id":115310627314723954,"email":"john@example.com","created_at":null,"updated_at":null,"first_name":"John","last_name":"Smith","state":"disabled","note":null,"verified_email":true,"multipass_identifier":null,"tax_exempt":false,"phone":null,"email_marketing_consent":{"state":"not_subscribed","opt_in_level":null,"consent_updated_at":null},"sms_marketing_consent":null,"tags":null,"currency":"USD","tax_exemptions":[],"admin_graphql_api_id":"gid://shopify/Customer/115310627314723954","default_address":{"id":715243470612851245,"customer_id":115310627314723954,"first_name":null,"last_name":null,"company":null,"address1":"123 Elm St.","address2":null,"city":"Ottawa","province":"Ontario","country":"Canada","zip":"K2H7A8","phone":"123-123-1234","name":null,"province_code":"ON","country_code":"CA","country_name":"Canada","default":true}},"discount_applications":[],"fulfillments":[],"line_items":[{"id":866550311766439020,"admin_graphql_api_id":"gid://shopify/LineItem/866550311766439020","attributed_staffs":[{"id":"gid://shopify/StaffMember/902541635","quantity":1}],"current_quantity":1,"fulfillable_quantity":1,"fulfillment_service":"manual","fulfillment_status":null,"gift_card":false,"grams":0,"name":"The Videographer Snowboard","price":"885.95","price_set":{"shop_money":{"amount":"885.95","currency_code":"USD"},"presentment_money":{"amount":"885.95","currency_code":"USD"}},"product_exists":true,"product_id":9313716535612,"properties":[],"quantity":1,"requires_shipping":true,"sku":null,"taxable":true,"title":"The Videographer Snowboard","total_discount":"0.00","total_discount_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"variant_id":49121760117052,"variant_inventory_management":"shopify","variant_title":null,"vendor":null,"tax_lines":[],"duties":[],"discount_allocations":[]},{"id":141249953214522974,"admin_graphql_api_id":"gid://shopify/LineItem/141249953214522974","attributed_staffs":[],"current_quantity":1,"fulfillable_quantity":1,"fulfillment_service":"manual","fulfillment_status":null,"gift_card":false,"grams":4536,"name":"The Complete Snowboard","price":"699.95","price_set":{"shop_money":{"amount":"699.95","currency_code":"USD"},"presentment_money":{"amount":"699.95","currency_code":"USD"}},"product_exists":true,"product_id":9313716601148,"properties":[],"quantity":1,"requires_shipping":true,"sku":null,"taxable":true,"title":"The Complete Snowboard","total_discount":"0.00","total_discount_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"variant_id":49121760182588,"variant_inventory_management":"shopify","variant_title":null,"vendor":null,"tax_lines":[],"duties":[],"discount_allocations":[]}],"payment_terms":null,"refunds":[],"shipping_address":{"first_name":"Steve","address1":"123 Shipping Street","phone":"555-555-SHIP","city":"Shippington","zip":"40003","province":"Kentucky","country":"United States","last_name":"Shipper","address2":null,"company":"Shipping Company","latitude":null,"longitude":null,"name":"Steve Shipper","country_code":"US","province_code":"KY"},"shipping_lines":[{"id":271878346596884015,"carrier_identifier":null,"code":null,"discounted_price":"10.00","discounted_price_set":{"shop_money":{"amount":"10.00","currency_code":"USD"},"presentment_money":{"amount":"10.00","currency_code":"USD"}},"is_removed":false,"phone":null,"price":"10.00","price_set":{"shop_money":{"amount":"10.00","currency_code":"USD"},"presentment_money":{"amount":"10.00","currency_code":"USD"}},"requested_fulfillment_service_id":null,"source":"shopify","title":"Generic Shipping","tax_lines":[],"discount_allocations":[]}]}', true);

        $syncOrder = new SyncShopifyOrderAction(
            $product->app,
            $product->company,
            $warehouse->region,
            $shopifyOrderData
        );

        $order = $syncOrder->execute();

        $this->assertInstanceOf(Order::class, $order);
        $this->assertEquals($order->items->count(), count($shopifyOrderData['line_items']));
        $this->assertEqualsIgnoringCase($order->getTotalAmount(), (float) $shopifyOrderData['current_total_price']);
        $this->assertEquals($order->getShopifyId($warehouse->region), $shopifyOrderData['id']);
    }
}
