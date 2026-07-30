<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderItem;
use Kanvas\Users\Models\Users;

/**
 * Applies a batch of item corrections (add / update / remove) as a single atomic
 * adjustment — one transaction, one total recalculation, one audit entry — mirroring
 * the "Ajustar items" modal where several line changes share one reason and evidence.
 */
class AdjustOrderItemsAction extends BaseOrderCorrectionAction
{
    /**
     * @param array<int, array<string, mixed>> $operations each: {op: add|update|remove, ...}
     */
    public function __construct(
        Order $order,
        Users $user,
        protected array $operations,
        protected string $reason,
        protected array $evidenceUrls = [],
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            if (empty($this->operations)) {
                throw new ValidationException('At least one operation is required to adjust items');
            }

            $changes = [];
            foreach ($this->operations as $operation) {
                $op = (string) ($operation['op'] ?? throw new ValidationException('Each operation requires an "op" field'));

                $changes[] = match ($op) {
                    'add' => $this->addItem($operation),
                    'update' => $this->updateItem($operation),
                    'remove' => $this->removeItem($operation),
                    default => throw new ValidationException("Unknown item operation: {$op}"),
                };
            }

            $this->order->calculateTotal();

            $this->logCorrection('adjust-amount', ['operations' => $changes], $this->reason, $this->evidenceUrls);

            return $this->order;
        });
    }

    private function addItem(array $data): array
    {
        $quantity = (int) ($data['quantity'] ?? 1);
        $amount = (float) ($data['amount'] ?? throw new ValidationException('amount is required to add an item'));

        if ($quantity < 1) {
            throw new ValidationException('Quantity must be at least 1');
        }

        if ($amount < 0) {
            throw new ValidationException('Amount cannot be negative');
        }

        $variant = Variants::find((int) ($data['variant_id'] ?? throw new ValidationException('variant_id is required to add an item')));

        if (! $variant) {
            throw new ValidationException('Variant not found');
        }

        // A correction carries an explicit amount, so build the line directly
        // instead of routing through OrderItem::from (which resolves warehouse pricing).
        $item = new OrderItem();
        $item->order_id = $this->order->getId();
        $item->apps_id = $this->order->apps_id;
        $item->product_name = $variant->product->name;
        $item->product_sku = $variant->sku;
        $item->variant_id = $variant->id;
        $item->variant_name = $variant->name;
        $item->quantity = $quantity;
        $item->unit_price_net_amount = $amount;
        $item->unit_price_gross_amount = $amount;
        $item->quantity_fulfilled = 0;
        $item->is_shipping_required = false;
        $item->tax_rate = 0;
        $item->currency = $this->order->currency ?: ($this->order->allItems()->value('currency') ?? 'USD');
        $item->is_public = 1;
        $item->saveOrFail();

        return [
            'op' => 'add',
            'order_item_id' => $item->id,
            'variant_id' => $variant->id,
            'product_name' => $item->product_name,
            'quantity' => $quantity,
            'amount' => $amount,
        ];
    }

    private function updateItem(array $data): array
    {
        $newAmount = isset($data['new_amount']) ? (float) $data['new_amount'] : null;
        $newQuantity = isset($data['new_quantity']) ? (int) $data['new_quantity'] : null;

        if ($newAmount === null && $newQuantity === null) {
            throw new ValidationException('Provide new_amount and/or new_quantity to update the item');
        }

        $item = $this->findItem((int) ($data['order_item_id'] ?? throw new ValidationException('order_item_id is required to update an item')));

        $change = [
            'op' => 'update',
            'order_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'product_name' => $item->product_name,
        ];

        if ($newAmount !== null) {
            if ($newAmount < 0) {
                throw new ValidationException('New amount cannot be negative');
            }
            $change['amount'] = ['old' => (float) $item->unit_price_net_amount, 'new' => $newAmount];
            $item->unit_price_net_amount = $newAmount;
            $item->unit_price_gross_amount = $newAmount;
        }

        if ($newQuantity !== null) {
            if ($newQuantity < 1) {
                throw new ValidationException('New quantity must be at least 1');
            }
            $change['quantity'] = ['old' => (int) $item->quantity, 'new' => $newQuantity];
            $item->quantity = $newQuantity;
        }

        $item->saveOrFail();

        return $change;
    }

    private function removeItem(array $data): array
    {
        $item = $this->findItem((int) ($data['order_item_id'] ?? throw new ValidationException('order_item_id is required to remove an item')));

        if ($this->isLocationItem((int) $item->variant_id)) {
            throw new ValidationException('The location item cannot be removed from the order');
        }

        $removed = [
            'op' => 'remove',
            'order_item_id' => $item->id,
            'variant_id' => $item->variant_id,
            'product_name' => $item->product_name,
            'quantity' => (int) $item->quantity,
            'amount' => (float) $item->unit_price_net_amount,
        ];

        $item->delete();

        return $removed;
    }

    private function findItem(int $orderItemId): OrderItem
    {
        /** @var OrderItem|null $item */
        $item = $this->order->allItems()->where('id', $orderItemId)->first();

        if (! $item) {
            throw new ValidationException("Order item {$orderItemId} not found on this order");
        }

        return $item;
    }

    private function isLocationItem(int $variantId): bool
    {
        return Variants::query()
            ->where('id', $variantId)
            ->whereHas('product.productType', fn ($q) => $q->where('slug', 'impound-lot'))
            ->exists();
    }
}
