<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\DataTransferObject;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\Enums\InvoiceStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;

/**
 * A Mercury AR invoice (`/ar/invoices`) — the customer-facing pay page Mercury collects on.
 *
 * Mercury is a DELIVERY AND COLLECTION channel, not a book of record. The invoice on our side (Scribe) is
 * the accounting truth; the Mercury copy exists so the customer gets a pay page and Mercury can take the
 * card/ACH. When they pay, the cash lands in the Mercury account, the bank feed picks it up, and the matcher
 * settles the Scribe invoice. Mercury's own `status` never posts a journal entry.
 */
class MercuryInvoice
{
    public const string SEND_EMAIL_OPTION = 'DontSend';

    public const string ACH_CURRENCY = 'USD';

    public function __construct(
        public readonly string $id,
        public readonly ?string $invoiceNumber,
        public readonly InvoiceStatusEnum $status,
        public readonly float $amount,
        public readonly ?string $slug = null,
        public readonly ?string $customerId = null,
        public readonly ?Carbon $invoiceDate = null,
        public readonly ?Carbon $dueDate = null,
        public readonly array $raw = [],
    ) {
    }

    public static function fromApi(array $payload): self
    {
        return new self(
            id: (string) $payload['id'],
            invoiceNumber: isset($payload['invoiceNumber']) ? (string) $payload['invoiceNumber'] : null,
            status: InvoiceStatusEnum::tryFrom((string) ($payload['status'] ?? ''))
                ?? InvoiceStatusEnum::UNPAID,
            amount: (float) ($payload['amount'] ?? 0),
            slug: isset($payload['slug']) ? (string) $payload['slug'] : null,
            customerId: isset($payload['customerId']) ? (string) $payload['customerId'] : null,
            invoiceDate: isset($payload['invoiceDate']) ? Carbon::parse((string) $payload['invoiceDate']) : null,
            dueDate: isset($payload['dueDate']) ? Carbon::parse((string) $payload['dueDate']) : null,
            raw: $payload,
        );
    }

    /**
     * The customer-facing pay page. This is the single most useful thing Mercury gives us back — it's what
     * gets embedded in a dunning email or handed to a customer who asks "where do I pay?".
     */
    public function payPageUrl(): ?string
    {
        return $this->slug !== null
            ? 'https://mercury.com/pay/' . $this->slug
            : null;
    }

    /**
     * Builds the POST /ar/invoices body from a Scribe invoice.
     *
     * Line items carry a `salesTaxRate`, not a tax amount — Mercury computes the tax itself. We pass the rate
     * we already hold per line and let it. Scribe stays the authority on what the customer owes; Mercury only
     * needs enough to render the document.
     *
     * @return array<string, mixed>
     */
    public static function payloadFromInvoice(
        Invoice $invoice,
        string $mercuryCustomerId,
        string $destinationAccountId,
        bool $cardEnabled = false,
    ): array {
        $invoice->loadMissing('lines');
        $currency = strtoupper($invoice->getPayableCurrency());

        $lineItems = $invoice->lines->map(fn (InvoiceLine $line): array => [
            'name' => (string) $line->description,
            'unitPrice' => round($line->unit_price_native, 2),
            'quantity' => $line->quantity,
            'salesTaxRate' => $line->tax_rate,
        ])->values()->all();

        return [
            'customerId' => $mercuryCustomerId,
            'destinationAccountId' => $destinationAccountId,
            'invoiceDate' => ($invoice->issued_date ?? Carbon::now())->toDateString(),
            'dueDate' => ($invoice->due_date ?? Carbon::now()->addDays(30))->toDateString(),
            'lineItems' => $lineItems,
            // Keep OUR number on their document, so a customer's remittance quotes something we recognise.
            'invoiceNumber' => $invoice->invoice_number,
            'payerMemo' => $invoice->notes,
            'ccEmails' => [],
            'currencyCode' => $currency,
            // Card needs a Stripe account connected inside Mercury and ACH debit is USD-only; either one
            // switched on without its precondition makes Mercury refuse the whole invoice with a 400.
            'creditCardEnabled' => $cardEnabled,
            'achDebitEnabled' => $currency === self::ACH_CURRENCY,
            'useRealAccountNumber' => false,
            // Kanvas sends every AR document itself, as a tracked engagement. Mercury is only the payment rail,
            // so it must never email the customer — and a DontSend invoice can't be sent later by API anyway.
            'sendEmailOption' => self::SEND_EMAIL_OPTION,
        ];
    }
}
