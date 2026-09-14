<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Kanvas\Souk\Discounts\Enums\CustomFieldEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Credits never change once issued; whatever is left after an order (or comes back when one is
 * cancelled) becomes a fresh single-use credit that points at the row and order it came from.
 */
class IssueDerivedCreditAction
{
    public function __construct(
        protected Discount $source,
        protected Order $order,
        protected float $amount
    ) {
    }

    public function execute(): Discount
    {
        $credit = Discount::create([
            'apps_id' => $this->source->apps_id,
            'companies_id' => $this->source->companies_id,
            'name' => $this->source->name,
            'description' => $this->source->description,
            'discount_type_id' => $this->source->discount_type_id,
            'value' => round($this->amount, 2),
            'is_percentage' => false,
            'code' => null,
            'is_active' => true,
            'usage_limit' => 1,
            'usage_count' => 0,
            'is_one_per_customer' => false,
        ]);

        $credit->set(CustomFieldEnum::PARENT_DISCOUNT_ID->value, $this->source->getId());
        $credit->set(CustomFieldEnum::SOURCE_ORDER_ID->value, $this->order->getId());

        return $credit;
    }
}
