<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WooCommerce\Actions;

use Automattic\WooCommerce\Client as WooCommerceClient;
use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WooCommerce\Client;
use Kanvas\Connectors\WooCommerce\Enums\CustomFieldEnum;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class PullOrderFromWooCommerceAction
{
    protected WooCommerceClient $wooCommerceClient;

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected Regions $region,
        protected int $wooCommerceOrderId,
        protected bool $runWorkflows = true,
    ) {
        $this->wooCommerceClient = (new Client($this->app))->getClient();
    }

    public function execute(): Order
    {
        // Check if order already exists in Kanvas by WooCommerce ID custom field
        $existingOrder = Order::getByCustomField(
            CustomFieldEnum::WOOCOMMERCE_ID->value,
            $this->wooCommerceOrderId,
            $this->company
        );

        if ($existingOrder) {
            return $existingOrder;
        }

        // Fetch the order from WooCommerce
        $wooCommerceOrder = $this->wooCommerceClient->get("orders/{$this->wooCommerceOrderId}");

        if (! $wooCommerceOrder) {
            throw new Exception("Order with ID {$this->wooCommerceOrderId} not found in WooCommerce");
        }

        // Create the order in Kanvas using the existing CreateOrderAction
        $createOrderAction = new CreateOrderAction(
            $this->app,
            $this->company,
            $this->user,
            $this->region,
            $wooCommerceOrder,
            $this->runWorkflows
        );

        $kanvasOrder = $createOrderAction->execute();

        // Store the WooCommerce order ID in the Kanvas order custom field
        $kanvasOrder->set(CustomFieldEnum::WOOCOMMERCE_ID->value, $this->wooCommerceOrderId);

        return $kanvasOrder;
    }
}
