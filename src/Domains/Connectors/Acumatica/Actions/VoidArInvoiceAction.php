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
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Payments\Models\Payment;
use Throwable;

/**
 * Voids a previously-pushed AR invoice's cash receipt in Acumatica — the AR mirror of
 * VoidApBillAction. Once a cash receipt (`Payment` entity, Type='Payment') is Released/Closed, its
 * application is read-only: `VoidPayment` is a no-op against a Closed payment (confirmed against a
 * live push — 204 response, zero effect after repeated polling), and re-PUTting the application with
 * AmountPaid=0 is silently ignored the same way (confirmed live too). Acumatica's own accounting model
 * doesn't delete a Closed cash receipt anyway — the correct reversal is a `Refund` (same `Payment`
 * entity, Type='Refund') for the same customer/amount, which nets the customer's cash position back to
 * zero without touching the original (now-historical) invoice or payment records. This mirrors how
 * VoidApBillAction handles the AP side: the original documents stay Closed, and an offsetting document
 * does the reversal.
 *
 * `OrigTransaction` looked like the natural link back to the original payment, but the API silently
 * drops it (confirmed live — it comes back empty on the created record), so the link between the
 * refund and what it's reversing is carried only in `Description`/`PaymentRef`, not a queryable field.
 */
class VoidArInvoiceAction
{
    use HasAcumaticaWriter;

    private const MAX_POLL_ATTEMPTS = 8;
    private const POLL_DELAY_SECONDS = 4;

    /** The invoice's own app — the tenant whose Acumatica config/credentials this void runs against. */
    protected Apps $app;

    public function __construct(
        protected Invoice $invoice,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $invoice->app;
        $this->writer = $writer;
    }

    /**
     * @return string the Acumatica ReferenceNbr of the Refund that reversed the cash receipt
     */
    public function execute(): string
    {
        $invoiceRef = (string) $this->invoice->get(CustomFieldEnum::INVOICE_REF->value, '');

        if ($invoiceRef === '') {
            throw new AcumaticaWriteException(
                "Invoice {$this->invoice->getId()} has no Acumatica reference — it must be pushed before it can be voided."
            );
        }

        $customerCode = $this->customerCode();

        if ($customerCode === '') {
            throw new AcumaticaWriteException(
                "Invoice {$this->invoice->getId()} has no customer Acumatica code — cannot void it."
            );
        }

        $payment = $this->payment();

        if ($payment === null) {
            throw new AcumaticaWriteException(
                "Invoice {$this->invoice->getId()} has no cash receipt to reverse."
            );
        }

        $paymentRef = (string) $payment->get(CustomFieldEnum::PAYMENT_REF->value, '');
        $amount = (float) $payment->amount_native;

        if ($paymentRef === '' || $amount <= 0.0) {
            throw new AcumaticaWriteException(
                "Payment {$payment->getId()} has no Acumatica reference or amount — cannot reverse it."
            );
        }

        return $this->writer()->withSession(
            function (Client $client) use ($customerCode, $paymentRef, $invoiceRef, $amount): string {
                $created = $client->put('Payment', AcumaticaPayload::wrap([
                    'Type' => 'Refund',
                    'CustomerID' => $customerCode,
                    'PaymentMethod' => 'CHECK',
                    'PaymentRef' => 'VOID-' . $paymentRef,
                    'CashAccount' => 'MAIN',
                    'PaymentAmount' => $amount,
                    'Description' => 'Void of payment ' . $paymentRef . ' / invoice ' . $invoiceRef,
                    'Hold' => false,
                ]));

                $refundId = AcumaticaPayload::recordId($created);
                $refundRef = (string) (AcumaticaPayload::value($created, 'ReferenceNbr') ?? '');

                if ($refundId === null || $refundRef === '') {
                    throw new AcumaticaWriteException('Refund creation did not return a usable id/ReferenceNbr.');
                }

                try {
                    $client->invokeAction('Payment', 'ReleasePayment', ['entity' => ['id' => $refundId]]);
                } catch (Throwable) {
                }

                $this->waitForRefundReleased($client, $refundRef);

                return $refundRef;
            }
        );
    }

    private function waitForRefundReleased(Client $client, string $refundRef): void
    {
        $filter = "ReferenceNbr eq '" . AcumaticaPayload::escapeLiteral($refundRef) . "'";

        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            $record = $client->get('Payment', ['$filter' => $filter, '$top' => 1])[0] ?? null;
            $status = $record !== null ? AcumaticaPayload::value($record, 'Status') : null;

            if ($status !== null && $status !== 'Balanced') {
                return;
            }

            sleep(self::POLL_DELAY_SECONDS);
        }

        throw new AcumaticaWriteException("Refund {$refundRef} did not release in time.");
    }

    private function customerCode(): string
    {
        $customer = $this->customerOrg();

        return $customer !== null ? (string) $customer->get(CustomFieldEnum::CUSTOMER_ID->value, '') : '';
    }

    private function customerOrg(): ?Organization
    {
        if ($this->invoice->customer_organization_id === null) {
            return null;
        }

        return Organization::query()->where('id', $this->invoice->customer_organization_id)->first();
    }

    private function payment(): ?Payment
    {
        $allocation = InvoicePaymentAllocation::query()
            ->where('invoice_id', $this->invoice->getId())
            ->where('status', '!=', 'reversed')
            ->first();

        if ($allocation === null || $allocation->payment_id === null) {
            return null;
        }

        return Payment::query()->where('id', $allocation->payment_id)->first();
    }
}
