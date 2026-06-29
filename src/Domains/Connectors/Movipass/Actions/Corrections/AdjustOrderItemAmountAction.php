<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions\Corrections;

use Kanvas\Exceptions\ValidationException;
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

            $serviceItem = $this->order
                ->allItems()
                ->with('variant.product.productType')
                ->get()
                ->first(fn ($item) => $item->variant?->product?->productType?->slug !== 'impound_lot');

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
}
