<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Client;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Connectors\Shopify\Services\ShopifyConfigurationService;
use Kanvas\Exceptions\EntityNotIntegratedException;
use Kanvas\Souk\Orders\Models\Order;
use PHPShopify\ShopifySDK;

class CreateShopifyDraftOrderAction
{
    protected ShopifySDK $shopifySdk;
    protected CompanyInterface $company;
    protected AppInterface $app;

    public function __construct(
        protected Order $order
    ) {
        $this->company = $order->company;
        $this->app = $order->app;

        $this->shopifySdk = Client::getInstance(
            $order->app,
            $order->company,
            $order->region->defaultWarehouse
        );
    }

    public function execute(): array
    {
        // Prepare draft order data
        $draftOrderData = $this->prepareDraftOrderData();
        $draftOrder = $this->shopifySdk->DraftOrder->create($draftOrderData);
        $this->saveDraftOrderId($draftOrder['id']);

        return $draftOrder;
    }

    protected function prepareDraftOrderData(): array
    {
        $lineItems = [];

        // Prepare line items
        foreach ($this->order->items as $item) {
            // Retrieve Shopify variant ID from our variant
            $shopifyVariantKey = ShopifyConfigurationService::getKey(
                CustomFieldEnum::SHOPIFY_VARIANT_ID->value,
                $this->company,
                $this->app,
                $this->order->region
            );

            $shopifyVariantId = $item->variant->get($shopifyVariantKey);

            if (! $shopifyVariantId) {
                // Log the error or handle it as needed
                throw new EntityNotIntegratedException($item->variant, 'Shopify');
            }

            $lineItems[] = [
                'variant_id' => $shopifyVariantId,
                'quantity' => $item->quantity,
                'price' => $item->price,
            ];
        }

        // Prepare customer data
        $customerData = [
            'first_name' => $this->order->people->firstname,
            'last_name' => $this->order->people->lastname,
            'email' => $this->order->email,
            'phone' => $this->order->phone,
        ];

        // Prepare shipping address
        $shippingAddress = $this->order->shippingAddress ? [
            'address1' => $this->order->shippingAddress->address,
            'address2' => $this->order->shippingAddress->address_2,
            'city' => $this->order->shippingAddress->city,
            'province' => $this->order->shippingAddress->state,
            'country' => $this->order->shippingAddress->country,
            'zip' => $this->order->shippingAddress->zipcode,
        ] : null;

        // Prepare draft order payload
        return [
            'draft_order' => [
                'line_items' => $lineItems,
                'customer' => $customerData,
                'shipping_address' => $shippingAddress,
                'note' => "Order #{$this->order->orderNumber} from our system",
                'total_price' => $this->order->total,
                'subtotal_price' => $this->order->total - $this->order->taxes,
                'total_tax' => $this->order->taxes,
                'currency' => $this->order->region->currency->code,
            ],
        ];
    }

    /**
     * Save Shopify draft order ID to our order
     */
    protected function saveDraftOrderId(int $shopifyDraftOrderId): void
    {
        $this->order->setCustomField(
            ShopifyConfigurationService::getKey(
                CustomFieldEnum::SHOPIFY_DRAFT_ORDER_ID->value,
                $this->company,
                $this->app,
                $this->order->region
            ),
            $shopifyDraftOrderId
        );
    }
}
