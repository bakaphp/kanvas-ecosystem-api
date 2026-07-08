<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Users\Models\Users;

class UpdateOrderItemAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected int $orderItemId,
        protected string $reason,
        protected array $evidenceUrls = [],
        protected ?float $newAmount = null,
        protected ?int $newQuantity = null,
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            if ($this->newAmount === null && $this->newQuantity === null) {
                throw new ValidationException('Provide new_amount and/or new_quantity to update the item');
            }

            /** @var OrderItem|null $item */
            $item = $this->order->allItems()->where('id', $this->orderItemId)->first();

            if (! $item) {
                throw new ValidationException('Order item not found on this order');
            }

            $changes = [
                'order_item_id' => $item->id,
                'variant_id' => $item->variant_id,
                'product_name' => $item->product_name,
            ];

            if ($this->newAmount !== null) {
                if ($this->newAmount < 0) {
                    throw new ValidationException('New amount cannot be negative');
                }
                $changes['amount'] = ['old' => (float) $item->unit_price_net_amount, 'new' => $this->newAmount];
                $item->unit_price_net_amount = $this->newAmount;
                $item->unit_price_gross_amount = $this->newAmount;
            }

            if ($this->newQuantity !== null) {
                if ($this->newQuantity < 1) {
                    throw new ValidationException('New quantity must be at least 1');
                }
                $changes['quantity'] = ['old' => (int) $item->quantity, 'new' => $this->newQuantity];
                $item->quantity = $this->newQuantity;
            }

            $item->saveOrFail();
            $this->order->calculateTotal();

            $this->logCorrection('update-item', $changes, $this->reason, $this->evidenceUrls);

            return $this->order;
        });
    }
}
