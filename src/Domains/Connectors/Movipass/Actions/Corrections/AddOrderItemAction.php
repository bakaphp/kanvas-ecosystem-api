<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Users\Models\Users;

class AddOrderItemAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected int $variantId,
        protected int $quantity,
        protected float $amount,
        protected string $reason,
        protected array $evidenceUrls = [],
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            if ($this->quantity < 1) {
                throw new ValidationException('Quantity must be at least 1');
            }

            if ($this->amount < 0) {
                throw new ValidationException('Amount cannot be negative');
            }

            $variant = Variants::find($this->variantId);

            if (! $variant) {
                throw new ValidationException('Variant not found');
            }

            // A correction carries an explicit amount, so build the line directly
            // instead of routing through OrderItem::from (which resolves warehouse pricing).
            $orderItem = new OrderItem();
            $orderItem->order_id = $this->order->getId();
            $orderItem->apps_id = $this->order->apps_id;
            $orderItem->product_name = $variant->product->name;
            $orderItem->product_sku = $variant->sku;
            $orderItem->variant_id = $variant->id;
            $orderItem->variant_name = $variant->name;
            $orderItem->quantity = $this->quantity;
            $orderItem->unit_price_net_amount = $this->amount;
            $orderItem->unit_price_gross_amount = $this->amount;
            $orderItem->quantity_fulfilled = 0;
            $orderItem->is_shipping_required = false;
            $orderItem->tax_rate = 0;
            $orderItem->currency = $this->order->currency ?: ($this->order->allItems()->value('currency') ?? 'USD');
            $orderItem->is_public = 1;
            $orderItem->saveOrFail();

            $this->order->calculateTotal();

            $this->logCorrection(
                'add-item',
                [
                    'order_item_id' => $orderItem->id,
                    'variant_id' => $variant->id,
                    'product_name' => $orderItem->product_name,
                    'quantity' => $this->quantity,
                    'amount' => $this->amount,
                ],
                $this->reason,
                $this->evidenceUrls,
            );

            return $this->order;
        });
    }
}
