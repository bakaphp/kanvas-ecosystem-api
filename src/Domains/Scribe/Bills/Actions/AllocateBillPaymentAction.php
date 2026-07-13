<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Bills\Services\BillJournalEntryComposerService;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Actions\CreateScribePaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use RuntimeException;

/**
 * Records an OUTBOUND payment against a RECEIVED Bill. Creates a Scribe.Payment if one isn't
 * passed, the allocation row, posts the Cash JE (DR AP / CR Cash), and recomputes the bill
 * balance — flipping to PAID when fully covered.
 *
 * Pass `$payment` when the Payment row was created elsewhere (Mercury reconciliation, ADM Cloud
 * sync). Omit it for ad-hoc operator entries or PDF auto-advance; one will be synthesized.
 */
class AllocateBillPaymentAction
{
    public function __construct(
        public readonly Bill $bill,
        public readonly float $amountNative,
        public readonly PaymentMethodEnum $method = PaymentMethodEnum::MANUAL,
        public readonly AccountSubTypeEnum $cashAccountSubType = AccountSubTypeEnum::CASH_CHECKING,
        public readonly ?Payment $payment = null,
        public readonly ?int $bankAccountId = null,
        public readonly ?string $reference = null,
        public readonly ?UserInterface $user = null,
        public readonly string $source = 'kanvas',
        public readonly ?array $metadata = null,
        public readonly ?Carbon $paidAt = null,
        /**
         * Set false ONLY when the cash movement was already booked elsewhere — specifically, when a bank
         * feed parked this payment in Suspense before the bill existed. Posting DR AP / CR Cash again would
         * credit cash twice for one real-world payment. The caller then owes the books a DR AP / CR Suspense
         * entry to clear the payable; SettleBillFromSuspenseAction is the only thing that should do this.
         */
        public readonly bool $postCashJournalEntry = true,
        protected readonly BillJournalEntryComposerService $composer = new BillJournalEntryComposerService(),
    ) {
    }

    public function execute(): BillPaymentAllocation
    {
        if ($this->bill->document_status !== BillDocumentStatusEnum::RECEIVED) {
            throw new RuntimeException(
                "Bill {$this->bill->id} must be RECEIVED to allocate a payment "
                . "(current status: {$this->bill->document_status->value}). Receive it first."
            );
        }

        if ($this->amountNative <= 0) {
            throw new RuntimeException("Payment allocation amount must be positive (got {$this->amountNative}).");
        }

        if ($this->amountNative > (float) $this->bill->balance_due_native + 0.0001) {
            throw new RuntimeException(
                "Payment amount {$this->amountNative} exceeds remaining balance "
                . "{$this->bill->balance_due_native} on bill {$this->bill->id}. "
                . 'Overpayments are not supported in v1.'
            );
        }

        return DB::connection('accounting')->transaction(function (): BillPaymentAllocation {
            $bill = $this->bill;
            $fxRate = (float) $bill->fx_rate_to_base;

            $payment = $this->payment ?? new CreateScribePaymentAction(
                app: $bill->app,
                company: $bill->company,
                amountNative: $this->amountNative,
                currency: $bill->currency,
                direction: PaymentDirectionEnum::OUTBOUND,
                method: $this->method,
                user: $this->user,
                fxRateToBase: $fxRate,
                paymentDate: $this->paidAt ?? Carbon::today(),
                bankAccountId: $this->bankAccountId,
                reference: $this->reference,
                notes: "Bill {$bill->bill_number} payment",
                source: $this->source,
                metadata: $this->metadata,
            )->execute();

            $allocation = new BillPaymentAllocation();
            $allocation->apps_id = (int) $bill->apps_id;
            $allocation->companies_id = (int) $bill->companies_id;
            $allocation->bill_id = (int) $bill->id;
            $allocation->payment_id = (int) $payment->id;
            $allocation->source_type = AllocationSourceTypeEnum::PAYMENT->value;
            $allocation->status = AllocationStatusEnum::ACTIVE;
            $allocation->amount_native = $this->amountNative;
            $allocation->amount_base = $this->amountNative * $fxRate;
            $allocation->currency = $bill->currency;
            $allocation->fx_rate_to_base = $fxRate;
            $allocation->fx_rate_at = $bill->fx_rate_at ?? Carbon::now();
            $allocation->allocated_at = $this->paidAt ?? Carbon::now();
            $allocation->allocated_by_users_id = $this->user?->getId();
            $allocation->source = $this->source;
            $allocation->metadata = $this->metadata;
            $allocation->save();

            if ($this->postCashJournalEntry) {
                $jeData = $this->composer->composePayment(
                    bill: $bill,
                    allocation: $allocation,
                    cashAccountSubType: $this->cashAccountSubType,
                );
                new PostJournalEntryAction(
                    data: $jeData,
                    postedByUser: $this->user,
                )->execute();
            }

            new MarkBillPaidAction(
                bill: $bill,
                user: $this->user,
            )->execute();

            return $allocation->refresh();
        });
    }
}
