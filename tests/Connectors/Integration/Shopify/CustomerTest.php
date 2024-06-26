<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Shopify;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Shopify\Actions\SyncShopifyCustomerAction;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyInventoryService;
use Kanvas\Inventory\Channels\Models\Channels;
use Kanvas\Inventory\Products\Models\Products;
use Tests\Connectors\Traits\HasShopifyConfiguration;
use Tests\TestCase;

final class CustomerTest extends TestCase
{
    use HasShopifyConfiguration;

    public function testCreateVariant()
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

        $shopifyCustomerData = [
            'id' => 115310627314723954,
            'email' => 'john@example.com',
            'created_at' => null,
            'updated_at' => null,
            'first_name' => 'John',
            'last_name' => 'Smith',
            'state' => 'disabled',
            'note' => null,
            'verified_email' => true,
            'multipass_identifier' => null,
            'tax_exempt' => false,
            'phone' => null,
            'email_marketing_consent' => [
                'state' => 'not_subscribed',
                'opt_in_level' => null,
                'consent_updated_at' => null,
            ],
            'sms_marketing_consent' => null,
            'tags' => null,
            'currency' => 'USD',
            'tax_exemptions' => [],
            'admin_graphql_api_id' => 'gid://shopify/Customer/115310627314723954',
            'default_address' => [
                'id' => 715243470612851245,
                'customer_id' => 115310627314723954,
                'first_name' => null,
                'last_name' => null,
                'company' => null,
                'address1' => '123 Elm St.',
                'address2' => null,
                'city' => 'Ottawa',
                'province' => 'Ontario',
                'country' => 'Canada',
                'zip' => 'K2H7A8',
                'phone' => '123-123-1234',
                'name' => null,
                'province_code' => 'ON',
                'country_code' => 'CA',
                'country_name' => 'Canada',
                'default' => true,
            ],
        ];

        $syncCustomer = new SyncShopifyCustomerAction(
            $app,
            $product->company,
            $region,
            $shopifyCustomerData
        );

        $shopifyCustomer = $syncCustomer->execute();

        $this->assertEquals(
            $shopifyCustomerData['first_name'],
            $shopifyCustomer->firstname
        );

        $this->assertEquals(
            $shopifyCustomer->get(CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value),
            $shopifyCustomerData['id']
        );

    }
}
