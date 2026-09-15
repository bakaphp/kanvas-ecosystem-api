<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Kanvas\Souk\Discounts\Enums\CustomFieldEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Orders\Models\Order;

class IssueDerivedCreditAction
{
    public function __construct(
        protected Discount $source,
        protected Order $order,
        protected float $amount
    ) {
    }

    public function execute(): ?Discount
    {
        $amount = round($this->amount, 2);

        if ($amount < 0.01) {
            return null;
        }

        $credit = Discount::create([
            'apps_id' => $this->source->apps_id,
            'companies_id' => $this->source->companies_id,
            'name' => $this->source->name,
            'description' => $this->source->description,
            'discount_type_id' => $this->source->discount_type_id,
            'value' => $amount,
            'is_percentage' => false,
            'code' => null,
            'start_date' => $this->source->start_date,
            'end_date' => $this->source->end_date,
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
