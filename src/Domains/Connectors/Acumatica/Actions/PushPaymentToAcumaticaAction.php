<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Models\Payment;

/**
 * Pushes a Kanvas payment (with its allocations) to Acumatica — the write half of the cash-application
 * use cases. One action covers both directions:
 *   - OUTBOUND (AP payment / UC3): applies the disbursement to the vendor's open Bills, closing them.
 *   - INBOUND  (AR receipt / UC2): applies the receipt to the customer's open Invoices.
 *
 * Kanvas is the system of record for the application (which docs, how much) — the matching/judgment
 * already happened in Scribe. This just mirrors the applied payment into the ERP. Idempotent by
 * ACUMATICA_PAYMENT_ID; retry-safe via the PaymentRef find-query.
 *
 * NOTE: the `Payment` entity and `Type` are confirmed against a live push; the DocumentsToApply
 * DocType ('INV' for AR, 'Bill' for AP) follows Acumatica's defaults — verify on the first live
 * payment that carries applications.
 */
class PushPaymentToAcumaticaAction
{
    use HasAcumaticaWriter;

    /** The payment's own app — the tenant whose Acumatica config/credentials this push runs against. */
    protected Apps $app;

    public function __construct(
        protected Payment $payment,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $payment->app;
        $this->writer = $writer;
    }

    public function execute(): string
    {
        if ($this->payment->source === 'acumatica') {
            throw new AcumaticaWriteException(
                "Payment {$this->payment->getId()} originated from Acumatica — cannot push it back."
            );
        }

        $existing = (string) $this->payment->get(CustomFieldEnum::PAYMENT_ID->value, '');

        if ($existing !== '') {
            return $existing;
        }

        $isAp = $this->payment->direction === PaymentDirectionEnum::OUTBOUND;
        [$partyCode, $documents] = $isAp ? $this->apApplications() : $this->arApplications();

        if ($partyCode === '') {
            throw new AcumaticaWriteException(
                "Payment {$this->payment->getId()} has no Acumatica " . ($isAp ? 'vendor' : 'customer') . ' code.'
            );
        }

        if ($documents === []) {
            throw new AcumaticaWriteException(
                "Payment {$this->payment->getId()} has no active allocations to apply."
            );
        }

        $record = $this->writer()->push(
            'Payment',
            $this->buildPayload($isAp, $partyCode, $documents),
            release: true,
            findQuery: $this->existingPaymentQuery(),
        );

        $id = AcumaticaPayload::recordId($record);
        $referenceNbr = (string) (AcumaticaPayload::value($record, 'ReferenceNbr') ?? $id ?? '');

        if ($id !== null) {
            $this->payment->set(CustomFieldEnum::PAYMENT_ID->value, $id);
        }

        if ($referenceNbr !== '') {
            $this->payment->set(CustomFieldEnum::PAYMENT_REF->value, $referenceNbr);
        }

        return $referenceNbr;
    }

    /**
     * @return array{0: string, 1: array<int, array<string, array{value: mixed}>>} vendor code + Bill applications
     */
    private function apApplications(): array
    {
        $allocations = BillPaymentAllocation::query()
            ->where('payment_id', $this->payment->getId())
            ->where('status', '!=', 'reversed')
            ->get();

        $partyCode = '';
        $docs = [];

        foreach ($allocations as $allocation) {
            $bill = $allocation->bill;

            if ($bill === null) {
                continue;
            }

            if ($partyCode === '') {
                $partyCode = (string) ($bill->vendor?->get(CustomFieldEnum::VENDOR_ID->value, '') ?? '');
            }

            $docs[] = AcumaticaPayload::wrap([
                'DocType' => 'Bill',
                'ReferenceNbr' => $bill->bill_number,
                'AmountPaid' => (float) $allocation->amount_native,
            ]);
        }

        return [$partyCode, $docs];
    }

    /**
     * @return array{0: string, 1: array<int, array<string, array{value: mixed}>>} customer code + INV applications
     */
    private function arApplications(): array
    {
        $allocations = InvoicePaymentAllocation::query()
            ->where('payment_id', $this->payment->getId())
            ->where('status', '!=', 'reversed')
            ->get();

        $partyCode = '';
        $docs = [];

        foreach ($allocations as $allocation) {
            $invoice = $allocation->invoice;

            if ($invoice === null) {
                continue;
            }

            if ($partyCode === '') {
                $partyCode = (string) ($invoice->customer?->get(CustomFieldEnum::CUSTOMER_ID->value, '') ?? '');
            }

            $docs[] = AcumaticaPayload::wrap([
                'DocType' => 'INV',
                'ReferenceNbr' => $invoice->invoice_number,
                'AmountPaid' => (float) $allocation->amount_native,
            ]);
        }

        return [$partyCode, $docs];
    }

    /**
     * @param array<int, array<string, array{value: mixed}>> $documents
     *
     * @return array<string, mixed>
     */
    private function buildPayload(bool $isAp, string $partyCode, array $documents): array
    {
        $header = AcumaticaPayload::wrap([
            'Type' => $isAp ? 'Check' : 'Payment',
            $isAp ? 'Vendor' : 'CustomerID' => $partyCode,
            'CashAccount' => $this->cashAccountCode(),
            'PaymentAmount' => (float) $this->payment->amount_native,
            'PaymentMethod' => strtoupper($this->payment->method->value),
            'PaymentRef' => $this->payment->reference,
            'ApplicationDate' => $this->payment->payment_date->toDateString(),
            'CurrencyID' => $this->payment->currency,
            // Defaults to Hold=true in this tenant, which disables Release entirely.
            'Hold' => false,
        ]);

        $header['DocumentsToApply'] = $documents;

        return $header;
    }

    /**
     * The Acumatica CashAccount the money moved through, mapped from the Kanvas bank account via its
     * ACUMATICA_CASH_ACCOUNT custom field. Null (dropped from the payload) when unmapped — Acumatica
     * then falls back to the payment method's default cash account for the branch.
     */
    private function cashAccountCode(): ?string
    {
        $code = $this->payment->bankAccount?->get(CustomFieldEnum::CASH_ACCOUNT->value);

        return $code !== null && $code !== '' ? (string) $code : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function existingPaymentQuery(): ?array
    {
        $ref = (string) ($this->payment->reference ?? '');

        if ($ref === '') {
            return null;
        }

        $ref = AcumaticaPayload::escapeLiteral($ref);

        return ['$filter' => "PaymentRef eq '{$ref}'", '$top' => 1];
    }
}
