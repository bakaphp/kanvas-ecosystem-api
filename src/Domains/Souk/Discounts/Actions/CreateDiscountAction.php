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
        $discount = new Discount();
        $discount->apps_id = $this->app->getId();
        $discount->companies_id = $this->company->getId();
        $discount->name = $this->data->name;
        $discount->description = $this->data->description;
        $discount->discount_type_id = $this->data->discount_type_id;
        $discount->value = $this->data->value;
        $discount->is_percentage = $this->data->is_percentage;
        $discount->min_order_value = $this->data->min_order_value ?? null;
        $discount->max_discount_amount = $this->data->max_discount_amount;
        $discount->code = $this->data->code;
        $discount->start_date = $this->data->start_date === null ? null : Carbon::instance($this->data->start_date);
        $discount->end_date = $this->data->end_date === null ? null : Carbon::instance($this->data->end_date);
        $discount->is_active = $this->data->is_active;
        $discount->usage_limit = $this->data->usage_limit ?? null;
        $discount->is_one_per_customer = $this->data->is_one_per_customer;
        $discount->saveOrFail();

        // Create conditions if provided
        if ($this->data->conditions->isNotEmpty()) {
            foreach ($this->data->conditions as $conditionData) {
                $action = new CreateDiscountConditionAction($this->app, $discount, $conditionData);
                $action->execute();
            }
        }

        return $discount;
    }
}
