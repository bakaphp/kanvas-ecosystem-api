<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Exception;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\OrderDiscount;
use Kanvas\Souk\Discounts\Services\DiscountService;
use Kanvas\Souk\Orders\Models\Order;

class ApplyDiscountToOrderAction
{
    protected DiscountService $discountService;

    public function __construct(
        protected Order $order,
        protected Discount $discount
    ) {
        $this->discountService = new DiscountService(
            $order->app,
            $order->company
        );
    }

    public function execute(): OrderDiscount
    {
        // Validate if discount can be applied
        if (! $this->discountService->canApplyToOrder($this->discount, $this->order)) {
            throw new Exception('Discount cannot be applied to this order');
        }

        // Calculate discount amount
        $discountAmount = $this->discount->calculateDiscountAmount($this->order->total_gross_amount ?? 0.0);

        // Create order discount record
        $orderDiscount = new OrderDiscount();
        $orderDiscount->apps_id = $this->order->apps_id;
        $orderDiscount->order_id = $this->order->getId();
        $orderDiscount->discount_id = $this->discount->getId();
        $orderDiscount->amount = $discountAmount;
        $orderDiscount->saveOrFail();

        // Update order with discount information
        $this->order->discount_amount = ($this->order->discount_amount ?? 0.0) + $discountAmount;
        $this->order->discount_name = $this->discount->name;
        $this->order->voucher_id = $this->discount->getId();
        $this->order->total_net_amount = ($this->order->total_gross_amount ?? 0.0) - $this->order->discount_amount;
        $this->order->saveOrFail();

        // Increment discount usage
        $this->discount->incrementUsage();

        return $orderDiscount;
    }
}
