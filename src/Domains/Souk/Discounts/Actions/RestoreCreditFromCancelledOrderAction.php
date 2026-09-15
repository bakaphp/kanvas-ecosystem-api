<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\OrderDiscount;
use Kanvas\Souk\Orders\Models\Order;

class RestoreCreditFromCancelledOrderAction
{
    public function __construct(
        protected Order $order
    ) {
    }

    /**
     * @return Discount[] the credits issued back to the company
     */
    public function execute(): array
    {
        return DB::connection('commerce')->transaction(function (): array {
            // The soft-deleted order_discounts row is the idempotency guard: a second cancel finds nothing to restore.
            // withTrashed: a credit deleted after it was spent still owes the company its money back.
            $consumed = $this->order->orderDiscounts()
                ->notDeleted()
                ->lockForUpdate()
                ->with(['discount' => fn (BelongsTo $discount) => $discount->withTrashed()->with('discountType')])
                ->get()
                ->filter(fn (OrderDiscount $orderDiscount) => $orderDiscount->discount?->isAutoAppliedCredit() ?? false);

            if ($consumed->isEmpty()) {
                return [];
            }

            $restored = [];

            foreach ($consumed as $orderDiscount) {
                $orderDiscount->is_deleted = true;
                $orderDiscount->saveOrFail();

                $restored[] = new IssueDerivedCreditAction(
                    $orderDiscount->discount,
                    $this->order,
                    $orderDiscount->amount
                )->execute();
            }

            if ($consumed->contains('discount_id', $this->order->voucher_id)) {
                $survivor = $this->order->orderDiscounts()->notDeleted()->with('discount')->first()?->discount;
                $this->order->voucher_id = $survivor?->getId();
                $this->order->discount_name = $survivor?->name;
            }

            $this->order->calculateTotal();

            return array_values(array_filter($restored));
        });
    }
}
