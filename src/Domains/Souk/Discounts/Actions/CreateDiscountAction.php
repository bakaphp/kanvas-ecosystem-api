<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountData;
use Kanvas\Souk\Discounts\Models\Discount;

class CreateDiscountAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected DiscountData $data
    ) {
    }

    public function execute(): Discount
    {
        $discount = Discount::firstOrCreate(
            [
            'code' => $this->data->code,
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            ],
            [
            'name' => $this->data->name,
            'description' => $this->data->description,
            'discount_type_id' => $this->data->discount_type_id,
            'value' => $this->data->value,
            'is_percentage' => $this->data->is_percentage,
            'min_order_value' => $this->data->min_order_value ?? null,
            'max_discount_amount' => $this->data->max_discount_amount,
            'start_date' => $this->data->start_date === null ? null : Carbon::instance($this->data->start_date),
            'end_date' => $this->data->end_date === null ? null : Carbon::instance($this->data->end_date),
            'is_active' => $this->data->is_active,
            'usage_limit' => $this->data->usage_limit ?? null,
            'is_one_per_customer' => $this->data->is_one_per_customer,
            ]
        );

        // Create conditions if provided
        if ($this->data->conditions->count()) {
            foreach ($this->data->conditions as $conditionData) {
                $action = new CreateDiscountConditionAction($this->app, $discount, $conditionData);
                $action->execute();
            }
        }

        return $discount;
    }
}
