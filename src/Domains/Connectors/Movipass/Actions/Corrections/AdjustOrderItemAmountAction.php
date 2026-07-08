<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Souk\Orders\Actions\Corrections\BaseOrderCorrectionAction;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

class AdjustOrderItemAmountAction extends BaseOrderCorrectionAction
{
    public function __construct(
        Order $order,
        Users $user,
        protected float $newAmount,
        protected string $reason,
        protected array $evidenceUrls = [],
        protected ?int $variantId = null,
    ) {
        parent::__construct($order, $user);
    }

    public function execute(): Order
    {
        return $this->transact(function () {
            $this->guardNotFinalStatus();

            if ($this->newAmount <= 0) {
                throw new ValidationException('New amount must be greater than zero');
            }

            $items = $this->order->allItems()->get();

            if ($this->variantId !== null) {
                $serviceItem = $items->first(fn ($item) => (int) $item->variant_id === $this->variantId);
            } else {
                $serviceVariantIds = $this->resolveServiceVariantIds($items->pluck('variant_id')->filter()->values()->all());
                $serviceItem = $items->first(fn ($item) => in_array($item->variant_id, $serviceVariantIds));
            }

            if (! $serviceItem) {
                throw new ValidationException('No service item found on this order to adjust');
            }

            $oldAmount = (float) $serviceItem->unit_price_net_amount;

            $serviceItem->unit_price_net_amount = $this->newAmount;
            $serviceItem->unit_price_gross_amount = $this->newAmount;
            $serviceItem->saveOrFail();

            $this->order->calculateTotal();

            $this->logCorrection(
                'adjust-amount',
                ['amount' => ['old' => $oldAmount, 'new' => $this->newAmount]],
                $this->reason,
                $this->evidenceUrls,
            );

            return $this->order;
        });
    }

    private function resolveServiceVariantIds(array $variantIds): array
    {
        if (empty($variantIds)) {
            return [];
        }

        return Variants::query()
            ->whereIn('id', $variantIds)
            ->whereHas('product.productType', fn ($q) => $q->where('slug', 'services'))
            ->pluck('id')
            ->toArray();
    }
}
