<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Payments\Actions\Concerns;

use Baka\Support\Money;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentStatusEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use RuntimeException;

trait GuardsPaymentAllocation
{
    /**
     * A payment recorded elsewhere (a bank match, a provider webhook) pays documents of its own company, in its
     * own direction (money received settles invoices, money paid out settles bills), while it stands, and never
     * more in total than it is worth: invoices and bills draw on the same amount. Compared in the ledger
     * currency, so a payment in another currency than the document still adds up.
     *
     * The payment row is locked, so allocations of one payment take turns and each sees what the one before
     * committed.
     */
    private function assertPaymentCovers(
        Payment $payment,
        Model $document,
        float $amountBase,
        PaymentDirectionEnum $direction
    ): void {
        $sameCompany = (int) $payment->apps_id === (int) $document->apps_id
            && (int) $payment->companies_id === (int) $document->companies_id;

        if (! $sameCompany) {
            throw new RuntimeException("Payment {$payment->getKey()} does not belong to this company.");
        }

        /** @var Payment $current */
        $current = $payment->newQueryWithoutScopes()
            ->whereKey($payment->getKey())
            ->lockForUpdate()
            ->firstOrFail();

        $settlesNothing = [
            PaymentStatusEnum::REVERSED,
            PaymentStatusEnum::FAILED,
        ];

        if (in_array($current->status, $settlesNothing, true)) {
            throw new RuntimeException(
                "Payment {$payment->getKey()} is {$current->status->value}: it cannot settle a document."
            );
        }

        if ($current->direction !== $direction) {
            throw new RuntimeException(
                "Payment {$payment->getKey()} is {$current->direction->value}: it cannot settle a document that "
                . "takes an {$direction->value} payment."
            );
        }

        $worth = Money::of($current->amount_base);
        $allocated = Money::of(0);

        $ledgers = [
            InvoicePaymentAllocation::class,
            BillPaymentAllocation::class,
        ];

        foreach ($ledgers as $allocations) {
            $allocated = $allocated->plus(Money::of(
                $allocations::on($payment->getConnectionName())
                    ->where('payment_id', $payment->getKey())
                    ->where('status', AllocationStatusEnum::ACTIVE->value)
                    ->sum('amount_base')
            ));
        }

        if ($allocated->plus(Money::of($amountBase))->isGreaterThan($worth->plus('0.005'))) {
            throw new RuntimeException(
                "Payment {$payment->getKey()} is worth " . Money::format($worth) . ' and '
                . Money::format($allocated) . ' of it is already allocated: ' . Money::format($amountBase)
                . ' more does not fit.'
            );
        }
    }
}
