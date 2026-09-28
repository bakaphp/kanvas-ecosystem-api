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

        // Create the order in Kanvas using the existing CreateOrderAction. Only the pull path resolves
        // order_number collisions — the default WooCommerce CreateOrderAction keeps its crash-on-dup
        // behavior; here we mint a non-colliding number so an inbound webhook never dies on it.
        $createOrderAction = new CreateOrderAction(
            $this->app,
            $this->company,
            $this->user,
            $this->region,
            $wooCommerceOrder,
            $this->runWorkflows,
            orderNumberOverride: self::resolveOrderNumber(
                $this->app,
                $this->company,
                (string) $wooCommerceOrder->number,
            ),
        );

        $kanvasOrder = $createOrderAction->execute();

        // Store the WooCommerce order ID + original WooCommerce order number. The number is kept as a
        // reference because CreateOrderAction may have minted a different order_number on our side to
        // dodge a collision (see resolveOrderNumber) — this preserves the link back to WordPress.
        $kanvasOrder->set(CustomFieldEnum::WOOCOMMERCE_ID->value, $this->wooCommerceOrderId);
        $kanvasOrder->set(CustomFieldEnum::WOOCOMMERCE_ORDER_NUMBER->value, (string) $wooCommerceOrder->number);

        return $kanvasOrder;
    }

    /**
     * Resolve the order_number to store on the Kanvas side for a pulled WooCommerce order.
     *
     * WooCommerce order numbers are only unique within a store, so a pulled number can collide with an
     * order already in Kanvas (a different WooCommerce order, or a manual/other-source order that
     * happens to share the number). Souk's CreateOrderAction hard-fails on that collision, which used
     * to crash the webhook pull. order_number is a bigint sequential column — we can't suffix it — so
     * on a collision we mint the next number in this app/company's sequence (the same max+1 logic as
     * Order::generateOrderNumber). The original WooCommerce number is preserved on the order via the
     * WOOCOMMERCE_ORDER_NUMBER custom field, and idempotency on redelivery is handled by the
     * WOOCOMMERCE_ID lookup at the top of execute(), so this does not need to be deterministic.
     */
    public static function resolveOrderNumber(
        Apps $app,
        Companies $company,
        string $wooCommerceNumber,
    ): string {
        $collision = Order::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->where('order_number', $wooCommerceNumber)
            ->exists();

        if (! $collision) {
            return $wooCommerceNumber;
        }

        $nextOrderNumber = (int) Order::where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->withTrashed()
            ->max('order_number') + 1;

        return (string) $nextOrderNumber;
    }
}
