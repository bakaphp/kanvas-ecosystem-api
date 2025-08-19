<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountConditionData;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountCondition;

class CreateDiscountConditionAction
{
    public function __construct(
        protected Apps $app,
        protected Discount $discount,
        protected DiscountConditionData $data
    ) {
    }

    public function execute(): DiscountCondition
    {
        $condition = new DiscountCondition();
        $condition->apps_id = $this->app->getId();
        $condition->discount_id = $this->discount->getId();
        $condition->type = $this->data->type;
        $condition->operator = $this->data->operator;
        $condition->saveOrFail();

        // Create condition values
        foreach ($this->data->values as $value) {
            $condition->values()->create([
                'apps_id' => $this->app->getId(),
                'value' => $value,
            ]);
        }

        return $condition;
    }
}
