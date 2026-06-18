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
use Kanvas\Souk\Payments\Models\Payments;
use RuntimeException;

/**
 * Allocates a payment against a RECEIVED Bill, posts the Cash JE (DR AP / CR Cash),
 * and recomputes the bill balance + flips status to PAID when the balance hits zero.
 *
 * Atomic side effects (one accounting-DB transaction):
 *   1. State guard — bill must be RECEIVED; drafts / voided / fully-paid rejected.
 *   2. Amount guard — > 0 and ≤ balance_due_native (no overpayment in v1).
 *   3. Insert `bill_payment_allocations` row (status=ACTIVE, source_type per payment presence).
 *   4. Compose + post Cash JE via `BillJournalEntryComposerService::composePayment` → `PostJournalEntryAction`.
 *   5. Delegate to `MarkBillPaidAction` to recompute `paid_native` / `balance_due_native` and run
 *      the state machine (RECEIVED → PAID when fully covered). It also emits `scribe.bill.paid`.
 *
 * Payment record handling — `?Payments $payment`:
 *   - When provided → `source_type = SOUK_PAYMENT`, `payment_id` set. Use for processor-backed
 *     payments (Authorize.Net, Stripe, etc.) where a Souk.Payments row exists.
 *   - When null → `source_type = MANUAL`, `payment_id = null`. Use for PDF-ingest auto-advance
 *     (the LLM told us the doc was already paid; no Souk.Payments row exists) or for ad-hoc
 *     "record paid outside the system" manual entries.
 */
class AllocateBillPaymentAction
{
    /**
     * @param  string  $source           Constrained by the `bill_payment_allocations.source` enum:
     *                                   one of 'kanvas' | 'adm_cloud' | 'manual'. Free-text discriminators
     *                                   (e.g. 'pdf_ingest_auto') belong in $metadata.
     * @param  array<string, mixed>|null $metadata  Optional audit payload — e.g. `['origin' => 'pdf_ingest_auto']`
     *                                   for ingest-triggered allocations, so the row can be filtered later
     *                                   without colliding with the enum-constrained source column.
     */
    public function __construct(
        public readonly Bill $bill,
        public readonly float $amountNative,
        public readonly AccountSubTypeEnum $cashAccountSubType = AccountSubTypeEnum::CASH_CHECKING,
        public readonly ?Payments $payment = null,
        public readonly ?UserInterface $user = null,
        public readonly string $source = 'manual',
        public readonly ?array $metadata = null,
        public readonly ?Carbon $paidAt = null,
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
            throw new RuntimeException(
                "Payment allocation amount must be positive (got {$this->amountNative})."
            );
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

            $allocation = new BillPaymentAllocation();
            $allocation->apps_id = (int) $bill->apps_id;
            $allocation->companies_id = (int) $bill->companies_id;
            $allocation->bill_id = (int) $bill->id;
            $allocation->payment_id = $this->payment?->id !== null ? (int) $this->payment->id : null;
            $allocation->source_type = $this->payment !== null
                ? AllocationSourceTypeEnum::SOUK_PAYMENT->value
                : AllocationSourceTypeEnum::MANUAL->value;
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

            $jeData = $this->composer->composePayment(
                bill: $bill,
                allocation: $allocation,
                cashAccountSubType: $this->cashAccountSubType,
            );
            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->user,
            )->execute();

            new MarkBillPaidAction(
                bill: $bill,
                user: $this->user,
            )->execute();

            return $allocation->refresh();
        });
    }
}
