<?php

declare(strict_types=1);

namespace Kanvas\Souk\Discounts\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Discounts\Models\Discount;
use Kanvas\Souk\Discounts\Models\OrderDiscount;
use Kanvas\Souk\Orders\Models\Order;

class ApplyCreditToOrderAction
{
    public function __construct(
        protected Order $order,
        protected Discount $credit
    ) {
    }

    public function execute(): OrderDiscount
    {
        return DB::connection('commerce')->transaction(function (): OrderDiscount {
            // Two orders for the same company can race for the same credit; the lock makes one of them burn it.
            $credit = Discount::query()->lockForUpdate()->findOrFail($this->credit->getId());

            $this->assertApplicable($credit);

            $this->order->calculateTotal(autoSave: false);
            $amount = round(min($credit->value, $this->order->remainingNetAmount()), 2);

            if ($amount < 0.01) {
                throw new ValidationException('Order has nothing left to credit');
            }

            $orderDiscount = OrderDiscount::create([
                'apps_id' => $this->order->apps_id,
                'order_id' => $this->order->getId(),
                'discount_id' => $credit->getId(),
                'amount' => $amount,
            ]);

            $credit->incrementUsage();

            if ($credit->value > $amount) {
                new IssueDerivedCreditAction($credit, $this->order, $credit->value - $amount)->execute();
            }

            if ($this->order->voucher_id === null) {
                $this->order->voucher_id = $credit->getId();
                $this->order->discount_name = $credit->name;
            }

            $this->order->calculateTotal();

            return $orderDiscount;
        });
    }

    protected function assertApplicable(Discount $credit): void
    {
        if (! $credit->isAutoAppliedCredit()) {
            throw new ValidationException('Discount is not an auto applied credit');
        }

        if ((int) $credit->apps_id !== (int) $this->order->apps_id
            || (int) $credit->companies_id !== (int) $this->order->buyerCompany()->getId()
        ) {
            throw new ValidationException('Credit belongs to another company');
        }

        if (! $credit->canBeUsed()) {
            throw new ValidationException('Credit has already been used');
        }

        if ($this->order->orderDiscounts()->where('discount_id', $credit->getId())->exists()) {
            throw new ValidationException('Credit is already applied to this order');
        }
    }
}
