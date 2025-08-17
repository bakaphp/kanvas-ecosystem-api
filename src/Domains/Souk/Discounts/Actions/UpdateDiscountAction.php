<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountData;
use Kanvas\Souk\Discounts\Models\Discount;

class UpdateDiscountAction
{
    public function __construct(
        protected Discount $discount,
        protected DiscountData $data
    ) {
    }

    public function execute(): Discount
    {
        $this->discount->name = $this->data->name;
        $this->discount->description = $this->discount->description ?? $this->data->description;
        $this->discount->discount_type_id = $this->data->discount_type_id;
        $this->discount->value = $this->data->value;
        $this->discount->is_percentage = $this->data->is_percentage;
        $this->discount->min_order_value = $this->data->min_order_value ?? $this->discount->min_order_value;
        $this->discount->max_discount_amount = $this->data->max_discount_amount ?? $this->discount->max_discount_amount;
        $this->discount->code = $this->data->code ?? $this->discount->code;
        $this->discount->start_date = $this->data->start_date === null ? null : Carbon::instance($this->data->start_date);
        $this->discount->end_date = $this->data->end_date === null ? null : Carbon::instance($this->data->end_date);
        $this->discount->is_active = $this->data->is_active;
        $this->discount->usage_limit = $this->data->usage_limit ?? $this->discount->usage_limit;
        $this->discount->is_one_per_customer = $this->data->is_one_per_customer;
        $this->discount->saveOrFail();

        // Update conditions if provided
        if ($this->data->conditions->count()) {
            // Remove existing conditions
            $this->discount->conditions()->delete();

            // Create new conditions
            foreach ($this->data->conditions as $conditionData) {
                $action = new CreateDiscountConditionAction($this->discount->app, $this->discount, $conditionData);
                $action->execute();
            }
        }

        return $this->discount;
    }
}
