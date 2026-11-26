<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentReceipts\Actions;

use Baka\Support\Str;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\PaymentReceipts\DataTransferObject\PaymentReceipt as PaymentReceiptData;
use Kanvas\Scribe\PaymentReceipts\Models\PaymentReceipt;
use Kanvas\Scribe\PaymentReceipts\Services\PaymentReceiptSettingsService;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentStatusEnum;
use Kanvas\Scribe\Payments\Models\Payment;

/**
 * Issues the receipt for a confirmed customer payment, numbered from the company's own sequence and listing
 * every invoice the payment covered. A payment has one receipt: asking again returns it.
 */
class CreatePaymentReceiptAction
{
    public const int NUMBER_PADDING = 7;

    public function __construct(
        protected readonly PaymentReceiptData $data,
        protected readonly DocumentNumberAllocatorService $allocator = new DocumentNumberAllocatorService(),
        protected readonly PaymentReceiptSettingsService $settings = new PaymentReceiptSettingsService(),
    ) {
    }

    public function execute(): PaymentReceipt
    {
        return DB::connection('accounting')->transaction(function (): PaymentReceipt {
            // Judged on the locked row, not on the copy the caller loaded: a payment reversed meanwhile gets none.
            $payment = Payment::query()
                ->whereKey($this->data->payment->getId())
                ->lockForUpdate()
                ->firstOrFail();
            $this->assertReceivable($payment);

            return PaymentReceipt::query()->where('payment_id', $payment->getId())->notDeleted()->first()
                ?? $this->issue($payment);
        });
    }

    private function issue(Payment $payment): PaymentReceipt
    {
        $allocations = $this->activeAllocations($payment);

        if ($allocations->isEmpty()) {
            throw new ValidationException(
                "Payment {$payment->getId()} is not applied to any invoice, so there is nothing for a receipt to cover."
            );
        }

        $invoice = $allocations->first()->invoice;

        return PaymentReceipt::create([
            'apps_id' => $payment->apps_id,
            'companies_id' => $payment->companies_id,
            'payment_id' => $payment->getId(),
            'receipt_number' => $this->allocator->allocate(
                $payment->apps_id,
                $payment->companies_id,
                DocumentTypeEnum::PAYMENT_RECEIPT,
                defaultPrefix: '',
                defaultPadding: self::NUMBER_PADDING,
            ),
            'customer_organization_id' => $invoice->customer_organization_id,
            'customer_name' => Str::trimToNull($invoice->billable_display_name) ?? (string) $invoice->customer?->name,
            'currency' => $payment->currency,
            'amount' => $payment->amount_native,
            'invoices' => $this->coveredInvoices($allocations),
            'payer_bank' => Str::trimToNull($this->data->payerBank),
            'instrument_number' => Str::trimToNull($this->data->instrumentNumber),
            'instrument_date' => $this->data->instrumentDate,
            'received_by' => $this->settings->receivedBy($payment->company),
            'issued_at' => Carbon::now(),
            'issued_by_users_id' => $this->data->user->getId(),
        ]);
    }

    private function assertReceivable(Payment $payment): void
    {
        $belongsToTenant = (int) $payment->apps_id === (int) $this->data->app->getId()
            && (int) $payment->companies_id === (int) $this->data->company->getId();

        if (! $belongsToTenant) {
            throw new ValidationException("Payment {$payment->getId()} was not found for this company.");
        }

        if ($payment->direction !== PaymentDirectionEnum::INBOUND) {
            throw new ValidationException(
                'A receipt is issued for money received from a customer, not for a payment we made.'
            );
        }

        if ($payment->status !== PaymentStatusEnum::CLEARED) {
            throw new ValidationException(
                "Payment {$payment->getId()} is {$payment->status->value}: "
                . 'a receipt is issued once the payment is confirmed.'
            );
        }
    }

    /**
     * @return Collection<int, InvoicePaymentAllocation>
     */
    private function activeAllocations(Payment $payment): Collection
    {
        return InvoicePaymentAllocation::query()
            ->where('payment_id', $payment->getId())
            ->where('status', AllocationStatusEnum::ACTIVE->value)
            ->with('invoice')
            ->get();
    }

    /**
     * @param Collection<int, InvoicePaymentAllocation> $allocations
     *
     * @return list<array{invoice_id: int, invoice_number: string|null, amount: float}>
     */
    private function coveredInvoices(Collection $allocations): array
    {
        return $allocations
            ->groupBy('invoice_id')
            ->map(fn (Collection $group): array => [
                'invoice_id' => (int) $group->first()->invoice_id,
                'invoice_number' => $group->first()->invoice?->invoice_number,
                'amount' => round((float) $group->sum('amount_native'), 4),
            ])
            ->values()
            ->all();
    }
}
