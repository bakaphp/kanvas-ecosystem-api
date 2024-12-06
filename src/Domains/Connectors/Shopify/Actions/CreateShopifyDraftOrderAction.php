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
            $order->region
        );
    }

    public function execute(): int
    {
        // Prepare draft order data
        if ($this->order->get(ShopifyConfigurationService::getKey(
            CustomFieldEnum::SHOPIFY_DRAFT_ORDER_ID->value,
            $this->company,
            $this->app,
            $this->order->region
        ))) {
            return $this->order->get(ShopifyConfigurationService::getKey(
                CustomFieldEnum::SHOPIFY_DRAFT_ORDER_ID->value,
                $this->company,
                $this->app,
                $this->order->region
            ));
        }

        $draftOrderData = $this->prepareDraftOrderData();
        $draftOrder = $this->shopifySdk->DraftOrder->post($draftOrderData);
        $this->saveDraftOrderId($draftOrder['id']);

        return $draftOrder['id'];
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

            $applyDiscount = $item->getPrice() !== $item->variant->getPrice($this->order->region->defaultWarehouse);
            $price = $applyDiscount ? ($item->variant->getPrice($this->order->region->defaultWarehouse) - $item->getPrice()) : $item->getPrice();
            $discount = $applyDiscount ? [
                'description' => 'Custom Price',
                'value_type' => 'fixed_amount',
                'value' => $price,
                'amount' => $price,
                'title' => 'Custom Price',
            ] : null;

            $lineItems[] = [
                'variant_id' => $shopifyVariantId,
                'quantity' => $item->quantity,
                'price' => $item->getPrice(),
                'applied_discount' => $discount,
                //'title' => $item->variant->product->name,
            ];
        }

        if (! $this->order->people->getEmails()->count() && $this->order->getEmail()) {
            $this->order->people->addEmail($this->order->getEmail());
        }

        if (! $this->order->people->getPhones()->count() && $this->order->getPhone()) {
            $this->order->people->addPhone($this->order->getPhone());
        }

        $createCustomer = new CreateShopifyCustomerAction($this->order->people, $this->order->region);
        $customer = $createCustomer->execute();

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
                'line_items' => $lineItems,
                'customer' => [
                    'id' => $customer,
                ],
                'shipping_address' => $shippingAddress,
                'note' => "Kanvas Order #{$this->order->order_number}",
                'total_price' => $this->order->getTotalAmount(),
                'subtotal_price' => $this->order->getSubTotalAmount(),
                'total_tax' => $this->order->getTotalTaxAmount(),
                'currency' => 'USD', //$this->order->region->currency->code,
        ];
    }

    /**
     * Save Shopify draft order ID to our order
     */
    protected function saveDraftOrderId(int $shopifyDraftOrderId): void
    {
        $this->order->set(
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
