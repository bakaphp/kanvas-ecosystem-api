<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Discounts;

use DateTime;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Souk\Discounts\Actions\CreateDiscountAction;
use Kanvas\Souk\Discounts\Actions\DeleteDiscountAction;
use Kanvas\Souk\Discounts\Actions\UpdateDiscountAction;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountConditionData;
use Kanvas\Souk\Discounts\DataTransferObject\DiscountData;
use Kanvas\Souk\Discounts\Models\Discount;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Optional;

class DiscountManagementMutation
{
    /**
     * Create a new discount.
     */
    public function create(mixed $root, array $request): Discount
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user ? $user->getCurrentCompany() : app(CompaniesBranches::class)->company;

        // Prepare conditions if provided
        $conditions = DiscountConditionData::collect([], DataCollection::class);
        if (! empty($request['input']['conditions'])) {
            $conditions = new DataCollection(
                DiscountConditionData::class,
                array_map(fn (array $condition) => new DiscountConditionData(
                    type: $condition['type'],
                    operator: $condition['operator'] ?? 'in',
                    values: $condition['values'] ?? []
                ), $request['input']['conditions'])
            );
        }

        $data = new DiscountData(
            name: $request['input']['name'],
            description: $request['input']['description'],
            discount_type_id: (int) $request['input']['discount_type_id'],
            value: (float) $request['input']['value'],
            is_percentage: $request['input']['is_percentage'] ?? false,
            min_order_value: isset($request['input']['min_order_value']) ? (float) $request['input']['min_order_value'] : null,
            max_discount_amount: isset($request['input']['max_discount_amount']) ? (float) $request['input']['max_discount_amount'] : null,
            code: $request['input']['code'] ?? null,
            start_date: isset($request['input']['start_date']) ? new DateTime((string) $request['input']['start_date']) : null,
            end_date: isset($request['input']['end_date']) ? new DateTime((string) $request['input']['end_date']) : null,
            is_active: $request['input']['is_active'] ?? true,
            usage_limit: isset($request['input']['usage_limit']) ? (int) $request['input']['usage_limit'] : null,
            is_one_per_customer: $request['input']['is_one_per_customer'] ?? false,
            conditions: $conditions
        );

        return new CreateDiscountAction($app, $company, $data)->execute();
    }

    /**
     * Update an existing discount.
     */
    public function update(mixed $root, array $request): Discount
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $discount = Discount::where('id', $request['id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->firstOrFail();

        // Prepare conditions if provided - keep existing if not provided
        $conditions = DiscountConditionData::collect([], DataCollection::class);
        if (! empty($request['input']['conditions'])) {
            $conditions = new DataCollection(
                DiscountConditionData::class,
                array_map(fn (array $condition) => new DiscountConditionData(
                    type: $condition['type'],
                    operator: $condition['operator'] ?? 'in',
                    values: $condition['values'] ?? []
                ), $request['input']['conditions'])
            );
        }

        $data = new DiscountData(
            name: $request['input']['name'] ?? $discount->name,
            description: $request['input']['description'] ?? ($discount->description ?: Optional::create()),
            discount_type_id: isset($request['input']['discount_type_id']) ? (int) $request['input']['discount_type_id'] : $discount->discount_type_id,
            value: isset($request['input']['value']) ? (float) $request['input']['value'] : $discount->value,
            is_percentage: $request['input']['is_percentage'] ?? $discount->is_percentage,
            min_order_value: isset($request['input']['min_order_value']) ? (float) $request['input']['min_order_value'] : $discount->min_order_value,
            max_discount_amount: isset($request['input']['max_discount_amount']) ? (float) $request['input']['max_discount_amount'] : $discount->max_discount_amount,
            code: $request['input']['code'] ?? $discount->code,
            start_date: isset($request['input']['start_date']) ? new DateTime($request['input']['start_date']) : $discount->start_date,
            end_date: isset($request['input']['end_date']) ? new DateTime($request['input']['end_date']) : $discount->end_date,
            is_active: $request['input']['is_active'] ?? $discount->is_active,
            usage_limit: isset($request['input']['usage_limit']) ? (int) $request['input']['usage_limit'] : $discount->usage_limit,
            is_one_per_customer: $request['input']['is_one_per_customer'] ?? $discount->is_one_per_customer,
            conditions: $conditions
        );

        return new UpdateDiscountAction($discount, $data)->execute();
    }

    /**
     * Delete a discount.
     */
    public function delete(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $discount = Discount::where('id', $request['id'])
            ->fromApp($app)
            ->fromCompany($company)
            ->firstOrFail();

        return new DeleteDiscountAction($discount)->execute();
    }
}
