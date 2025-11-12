<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Services;

use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Discounts\Actions\ApplyDiscountToOrderAction;
use Kanvas\Souk\Discounts\Enums\DiscountConditionTypeEnum;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\DiscountCondition;
use Kanvas\Souk\Orders\Models\Order;

class DiscountService
{
    public function __construct(
        protected Apps $app,
        protected Companies $company
    ) {
    }

    /**
     * Find a discount by code
     */
    public function findByCode(string $code): ?Discount
    {
        return Discount::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('code', $code)
            ->where('is_deleted', false)
            ->first();
    }

    /**
     * Get all active discounts
     */
    public function getActiveDiscounts(): Builder
    {
        return Discount::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_active', true)
            ->where('is_deleted', false)
            ->where(function ($query): void {
                $query->whereNull('start_date')
                    ->orWhere('start_date', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('end_date')
                    ->orWhere('end_date', '>=', now());
            });
    }

    /**
     * Check if a discount can be applied to an order
     */
    public function canApplyToOrder(Discount $discount, Order $order): bool
    {
        // Check if discount is valid
        if (! $discount->canBeUsed()) {
            return false;
        }

        // Check minimum order value
        if ($discount->min_order_value !== null && $order->total_gross_amount < $discount->min_order_value) {
            return false;
        }

        // Check if discount has already been applied to this order
        if ($order->orderDiscounts()->where('discount_id', $discount->getId())->exists()) {
            return false;
        }

        // Check one per customer limit
        if ($discount->is_one_per_customer && $order->people_id !== null) {
            $hasUsedDiscount = Order::query()
                ->where('people_id', $order->people_id)
                ->whereHas('orderDiscounts', function ($query) use ($discount) {
                    $query->where('discount_id', $discount->getId());
                })
                ->exists();

            if ($hasUsedDiscount) {
                return false;
            }
        }

        // Check conditions
        if ($discount->conditions()->exists()) {
            return $this->checkOrderConditions($discount, $order);
        }

        return true;
    }

    /**
     * Check if order meets discount conditions
     */
    protected function checkOrderConditions(Discount $discount, Order $order): bool
    {
        foreach ($discount->conditions as $condition) {
            $meets = false;

            switch ($condition->type) {
                case DiscountConditionTypeEnum::PRODUCT->value:
                    $meets = $this->checkProductCondition($condition, $order);

                    break;
                case DiscountConditionTypeEnum::CATEGORY->value:
                    $meets = $this->checkCategoryCondition($condition, $order);

                    break;
                case DiscountConditionTypeEnum::VARIANT->value:
                    $meets = $this->checkVariantCondition($condition, $order);

                    break;
                case DiscountConditionTypeEnum::CUSTOMER->value:
                    $meets = $this->checkCustomerCondition($condition, $order);

                    break;
                case DiscountConditionTypeEnum::CUSTOMER_GROUP->value:
                    $meets = $this->checkCustomerGroupCondition($condition, $order);

                    break;
            }

            if (! $meets) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check product condition
     */
    protected function checkProductCondition(DiscountCondition $condition, Order $order): bool
    {
        $productIds = $order->items->pluck('variant.product_id')->unique()->toArray();
        $conditionProductIds = $condition->values()->pluck('value')->toArray();

        if ($condition->operator === 'in') {
            return ! empty(array_intersect($productIds, $conditionProductIds));
        }

        return empty(array_intersect($productIds, $conditionProductIds));
    }

    /**
     * Check category condition
     */
    protected function checkCategoryCondition(DiscountCondition $condition, Order $order): bool
    {
        $categoryIds = [];
        foreach ($order->items as $item) {
            if ($item->variant && $item->variant->product) {
                $product = $item->variant->product;
                $categoryIds = array_merge($categoryIds, $product->categories()->pluck('id')->toArray());
            }
        }
        $categoryIds = array_unique($categoryIds);
        $conditionCategoryIds = $condition->values()->pluck('value')->toArray();

        if ($condition->operator === 'in') {
            return ! empty(array_intersect($categoryIds, $conditionCategoryIds));
        }

        return empty(array_intersect($categoryIds, $conditionCategoryIds));
    }

    /**
     * Check variant condition
     */
    protected function checkVariantCondition(DiscountCondition $condition, Order $order): bool
    {
        $variantIds = $order->items->pluck('variant_id')->toArray();
        $conditionVariantIds = $condition->values()->pluck('value')->toArray();

        if ($condition->operator === 'in') {
            return ! empty(array_intersect($variantIds, $conditionVariantIds));
        }

        return empty(array_intersect($variantIds, $conditionVariantIds));
    }

    /**
     * Check customer condition
     */
    protected function checkCustomerCondition(DiscountCondition $condition, Order $order): bool
    {
        if ($order->people_id === null) {
            return false;
        }

        return $condition->matches((string) $order->people_id);
    }

    /**
     * Check customer group condition
     */
    protected function checkCustomerGroupCondition(DiscountCondition $condition, Order $order): bool
    {
        if ($order->people_id === null) {
            return false;
        }

        // This would need to be implemented based on your customer group logic
        // For now, returning true as placeholder
        return true;
    }

    /**
     * Get applicable discounts for an order
     */
    public function getApplicableDiscounts(Order $order): Collection
    {
        $activeDiscounts = $this->getActiveDiscounts()->get();

        return $activeDiscounts->filter(function ($discount) use ($order) {
            return $this->canApplyToOrder($discount, $order);
        });
    }

    /**
     * Apply discount code to order
     */
    public function applyDiscountCode(string $code, Order $order): ?Discount
    {
        $discount = $this->findByCode($code);

        if (! $discount || ! $this->canApplyToOrder($discount, $order)) {
            return null;
        }

        $action = new ApplyDiscountToOrderAction($order, $discount);
        $action->execute();

        return $discount;
    }

    /**
     * Calculate total discount for multiple discounts
     */
    public function calculateTotalDiscount(array $discounts, float $orderTotal): float
    {
        $totalDiscount = 0.0;

        foreach ($discounts as $discount) {
            if ($discount instanceof Discount) {
                $totalDiscount += $discount->calculateDiscountAmount($orderTotal - $totalDiscount);
            }
        }

        return $totalDiscount;
    }
}
