<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Users\Models\Users;

class RemoveOrderItemAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected int $orderItemId,
        protected string $reason,
        protected array $evidenceUrls = [],
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            /** @var OrderItem|null $item */
            $item = $this->order->allItems()->where('id', $this->orderItemId)->first();

            if (! $item) {
                throw new ValidationException('Order item not found on this order');
            }

            if ($this->isLocationItem((int) $item->variant_id)) {
                throw new ValidationException('The location item cannot be removed from the order');
            }

            $removed = [
                'order_item_id' => $item->id,
                'variant_id' => $item->variant_id,
                'product_name' => $item->product_name,
                'quantity' => (int) $item->quantity,
                'amount' => (float) $item->unit_price_net_amount,
            ];

            $item->delete();
            $this->order->calculateTotal();

            $this->logCorrection('remove-item', $removed, $this->reason, $this->evidenceUrls);

            return $this->order;
        });
    }

    private function isLocationItem(int $variantId): bool
    {
        return Variants::query()
            ->where('id', $variantId)
            ->whereHas('product.productType', fn ($q) => $q->where('slug', 'impound-lot'))
            ->exists();
    }
}
