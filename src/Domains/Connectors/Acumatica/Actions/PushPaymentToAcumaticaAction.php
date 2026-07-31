<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Client;
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
 * AP applications go through the `Check` entity (Vendor field, Details array); AR through `Payment` (CustomerID field, DocumentsToApply array) — confirmed live, they are not interchangeable.
 *
 * AP is a two-step PUT (Dennis, 2026-07-29): sending PaymentAmount together with the Details application
 * line on a single create makes the header amount land as 0 while the line still shows the real amount,
 * and Hold=false on that same call forces balance validation immediately — so it fails as "out of
 * balance" even though the application line itself is correct. Creating on Hold=true first (no
 * PaymentAmount) skips validation and lands the line; a second PUT keyed on the returned ReferenceNbr
 * then sets PaymentAmount and flips Hold to false, which both sticks the header amount and releases it.
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

        $record = $isAp
            ? $this->pushApCheck($partyCode, $documents)
            : $this->writer()->push(
                'Payment',
                $this->buildArPayload($partyCode, $documents),
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
                // The Acumatica ReferenceNbr set when the bill was pushed — not bill_number, which is
                // Kanvas's own document number and won't resolve on Acumatica's side.
                'ReferenceNbr' => (string) $bill->get(CustomFieldEnum::BILL_REF->value, ''),
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
                // The Acumatica ReferenceNbr set when the invoice was pushed — not invoice_number,
                // which is Kanvas's own document number and won't resolve on Acumatica's side.
                'ReferenceNbr' => (string) $invoice->get(CustomFieldEnum::INVOICE_REF->value, ''),
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
    private function buildArPayload(string $partyCode, array $documents): array
    {
        $header = AcumaticaPayload::wrap([
            'Type' => 'Payment',
            'CustomerID' => $partyCode,
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
     * @param array<int, array<string, array{value: mixed}>> $documents
     *
     * @return array<string, mixed> the released Check record
     */
    private function pushApCheck(string $vendorCode, array $documents): array
    {
        return $this->writer()->withSession(function (Client $client) use ($vendorCode, $documents): array {
            $findQuery = $this->existingPaymentQuery();

            if ($findQuery !== null) {
                $found = $client->get('Check', $findQuery);

                if (isset($found[0]) && is_array($found[0])) {
                    return $found[0];
                }
            }

            // Step 1: create on hold with the application line, no header amount yet — sending
            // PaymentAmount alongside Details on the same call makes it land as 0 on the header.
            $created = AcumaticaPayload::wrap([
                'Type' => 'Payment',
                'Vendor' => $vendorCode,
                'CashAccount' => $this->cashAccountCode(),
                'PaymentMethod' => strtoupper($this->payment->method->value),
                'PaymentRef' => $this->payment->reference,
                'ApplicationDate' => $this->payment->payment_date->toDateString(),
                'CurrencyID' => $this->payment->currency,
                'Hold' => true,
            ]);
            $created['Details'] = $documents;

            $record = $client->put('Check', $created);
            $referenceNbr = (string) (AcumaticaPayload::value($record, 'ReferenceNbr') ?? '');

            if ($referenceNbr === '') {
                throw new AcumaticaWriteException(
                    "Payment {$this->payment->getId()}: AP Check creation did not return a usable ReferenceNbr."
                );
            }

            // Step 2: the application line now exists, so the header amount sticks — set it and
            // release by flipping Hold to false.
            return $client->put('Check', AcumaticaPayload::wrap([
                'Type' => 'Payment',
                'ReferenceNbr' => $referenceNbr,
                'PaymentAmount' => (float) $this->payment->amount_native,
                'Hold' => false,
            ]));
        });
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
