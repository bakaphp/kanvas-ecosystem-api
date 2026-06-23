<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Payments\Actions\CreateScribePaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use RuntimeException;

/**
 * Records a payment against an ISSUED/SENT Invoice. Creates a Scribe.Payment if one isn't passed,
 * the allocation row, posts the Cash JE (DR Cash / CR AR), and recomputes the invoice balance —
 * flipping to PAID when fully covered.
 *
 * Pass `$payment` when the Payment row was created elsewhere (Mercury reconciliation, Stripe Billing
 * webhook, ADM Cloud sync). Omit it for ad-hoc operator entries; one will be synthesized using
 * the provided method + bank account.
 */
class AllocateInvoicePaymentAction
{
    public function __construct(
        public readonly Invoice $invoice,
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
        protected readonly InvoiceJournalEntryComposerService $composer = new InvoiceJournalEntryComposerService(),
    ) {
    }

    public function execute(): InvoicePaymentAllocation
    {
        if (! in_array($this->invoice->document_status, [
            InvoiceDocumentStatusEnum::ISSUED,
            InvoiceDocumentStatusEnum::SENT,
        ], true)) {
            throw new RuntimeException(
                "Invoice {$this->invoice->id} must be ISSUED or SENT to allocate a payment "
                . "(current status: {$this->invoice->document_status->value}). Issue it first."
            );
        }

        if ($this->amountNative <= 0) {
            throw new RuntimeException("Payment allocation amount must be positive (got {$this->amountNative}).");
        }

        if ($this->amountNative > (float) $this->invoice->balance_due_native + 0.0001) {
            throw new RuntimeException(
                "Payment amount {$this->amountNative} exceeds remaining balance "
                . "{$this->invoice->balance_due_native} on invoice {$this->invoice->id}. "
                . 'Overpayments are not supported in v1.'
            );
        }

        return DB::connection('accounting')->transaction(function (): InvoicePaymentAllocation {
            $invoice = $this->invoice;
            $fxRate = (float) $invoice->fx_rate_to_base;

            $payment = $this->payment ?? new CreateScribePaymentAction(
                app: $invoice->app,
                company: $invoice->company,
                amountNative: $this->amountNative,
                currency: $invoice->currency,
                direction: PaymentDirectionEnum::INBOUND,
                method: $this->method,
                user: $this->user,
                fxRateToBase: $fxRate,
                paymentDate: $this->paidAt ?? Carbon::today(),
                bankAccountId: $this->bankAccountId,
                reference: $this->reference,
                notes: "Invoice {$invoice->invoice_number} payment",
                source: $this->source,
                metadata: $this->metadata,
            )->execute();

            $allocation = new InvoicePaymentAllocation();
            $allocation->apps_id = (int) $invoice->apps_id;
            $allocation->companies_id = (int) $invoice->companies_id;
            $allocation->invoice_id = (int) $invoice->id;
            $allocation->payment_id = (int) $payment->id;
            $allocation->source_type = AllocationSourceTypeEnum::PAYMENT->value;
            $allocation->status = AllocationStatusEnum::ACTIVE;
            $allocation->amount_native = $this->amountNative;
            $allocation->amount_base = $this->amountNative * $fxRate;
            $allocation->currency = $invoice->currency;
            $allocation->fx_rate_to_base = $fxRate;
            $allocation->fx_rate_at = $invoice->fx_rate_at ?? Carbon::now();
            $allocation->allocated_at = $this->paidAt ?? Carbon::now();
            $allocation->allocated_by_users_id = $this->user?->getId();
            $allocation->source = $this->source;
            $allocation->metadata = $this->metadata;
            $allocation->save();

            $jeData = $this->composer->composePayment(
                invoice: $invoice,
                allocation: $allocation,
                cashAccountSubType: $this->cashAccountSubType,
            );
            new PostJournalEntryAction(
                data: $jeData,
                postedByUser: $this->user,
            )->execute();

            new MarkInvoicePaidAction(
                invoice: $invoice,
                user: $this->user,
            )->execute();

            return $allocation->refresh();
        });
    }
}
