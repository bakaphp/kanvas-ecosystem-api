<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Workflow\Enums\IntegrationsEnum;

/** Pushes a Kanvas invoice to Acumatica as an AR Invoice (create + Release), the AR mirror of PushBillToAcumaticaAction. */
class PushInvoiceToAcumaticaAction
{
    use HasAcumaticaWriter;

    /** The invoice's own app — the tenant whose Acumatica config/credentials this push runs against. */
    protected Apps $app;

    public function __construct(
        protected Invoice $invoice,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $invoice->app;
        $this->writer = $writer;
    }

    /**
     * @return string the Acumatica ReferenceNbr (or record id) of the created invoice
     */
    public function execute(): string
    {
        // Seatbelt: the real guarantee is that the push only runs from the approval handler,
        // but this catches a call site added later that skips it and would otherwise send an
        // un-approved invoice to Acumatica.
        $this->invoice->assertApproved();

        if ($this->invoice->source === IntegrationsEnum::ACUMATICA->value) {
            throw new AcumaticaWriteException(
                "Invoice {$this->invoice->getId()} originated from Acumatica — cannot push it back."
            );
        }

        $existing = (string) $this->invoice->get(CustomFieldEnum::INVOICE_ID->value, '');

        if ($existing !== '') {
            return (string) $this->invoice->get(CustomFieldEnum::INVOICE_REF->value, $existing);
        }

        $customerCode = $this->ensureCustomerCode();

        if ($customerCode === '') {
            throw new AcumaticaWriteException(
                "Invoice {$this->invoice->getId()} has no customer organization — assign a customer before pushing."
            );
        }

        $record = $this->writer()->push(
            'Invoice',
            $this->buildPayload($customerCode),
            release: true,
            findQuery: $this->existingInvoiceQuery($customerCode),
        );

        $id = AcumaticaPayload::recordId($record);
        $referenceNbr = (string) (AcumaticaPayload::value($record, 'ReferenceNbr') ?? $id ?? '');

        if ($id !== null) {
            $this->invoice->set(CustomFieldEnum::INVOICE_ID->value, $id);
        }

        if ($referenceNbr !== '') {
            $this->invoice->set(CustomFieldEnum::INVOICE_REF->value, $referenceNbr);
        }

        return $referenceNbr;
    }

    /** Disabled — CustomerRef isn't $filter-able on this endpoint's Invoice entity (throws a raw KeyNotFoundException). */
    private function existingInvoiceQuery(string $customerCode): ?array
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $customerCode): array
    {
        $header = AcumaticaPayload::wrap([
            'Type' => $this->invoice->isCreditNote() ? 'Credit Memo' : 'Invoice',
            'Customer' => $customerCode,
            'CustomerRef' => $this->invoice->invoice_number,
            'Description' => $this->invoice->notes ?? ('Kanvas invoice ' . $this->invoice->invoice_number),
            'Date' => $this->invoice->issued_date?->toDateString(),
            'DueDate' => $this->invoice->due_date?->toDateString(),
            'CurrencyID' => $this->invoice->currency,
            // A bare API-created customer has no default Location/Terms for Acumatica to derive.
            'LocationID' => 'MAIN',
            'Terms' => 'NET60',
            'Hold' => false,
            // Intermittently flags brand-new customers into Credit Hold regardless of CreditLimit.
            'CreditHold' => false,
            // Header-level hint; the line-level TaxCategory in buildLines() is what actually drives the calc.
            'TaxZone' => $this->taxExemptCode(),
        ]);

        $header['Details'] = $this->buildLines();

        return $header;
    }

    /**
     * Only exempts when Kanvas itself computed zero tax — a normal taxable document is untouched.
     * The same configured code is used for both the header TaxZone and each line's TaxCategory:
     * Acumatica computes tax off the line's TaxCategory (which otherwise defaults from the GL
     * account), not the header TaxZone alone — setting only the header leaves VAT applied per line.
     */
    private function taxExemptCode(): ?string
    {
        if ((float) $this->invoice->tax_native !== 0.0) {
            return null;
        }

        $code = (string) $this->app->get(ConfigurationEnum::ACUMATICA_TAX_EXEMPT_ZONE->value);

        return $code !== '' ? $code : null;
    }

    /**
     * @return array<int, array<string, array{value: mixed}>>
     */
    private function buildLines(): array
    {
        $lines = [];
        $defaultSubaccount = (string) $this->app->get(ConfigurationEnum::ACUMATICA_DEFAULT_SUBACCOUNT->value);
        $taxExemptCode = $this->taxExemptCode();

        foreach ($this->invoice->lines as $line) {
            /** @var InvoiceLine $line */
            $accountCode = $this->lineAccountCode($line->account_id);

            $lines[] = AcumaticaPayload::wrap([
                'Description' => $line->description,
                'Qty' => (float) $line->quantity,
                'UnitPrice' => (float) $line->unit_price_native,
                'Account' => $accountCode,
                'Subaccount' => $defaultSubaccount !== '' ? $defaultSubaccount : null,
                'TaxCategory' => $taxExemptCode,
            ]);
        }

        return $lines;
    }

    /** Resolves a line's own GL account (e.g. a rebate's Control Acct#) to the Acumatica account code; null lets Acumatica derive its default. */
    private function lineAccountCode(?int $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        return Account::query()->where('id', $accountId)->value('account_number');
    }

    /** Find-or-create the customer in Acumatica, mirroring EnsureAcumaticaVendorAction for the AP side. */
    private function ensureCustomerCode(): string
    {
        $customer = $this->customerOrg();

        if ($customer === null) {
            return '';
        }

        $existing = (string) $customer->get(CustomFieldEnum::CUSTOMER_ID->value, '');

        if ($existing !== '') {
            return $existing;
        }

        $name = (string) ($this->invoice->billable_display_name ?: $customer->name);

        $record = $this->writer()->findOrCreate(
            'Customer',
            ['$filter' => "CustomerName eq '" . AcumaticaPayload::escapeLiteral($name) . "'", '$top' => 1],
            AcumaticaPayload::wrap([
                'CustomerName' => $name,
                'Email' => $this->invoice->billable_email,
                // CreditLimit=0 lands every invoice in Credit Hold — give new customers headroom.
                'CreditLimit' => 999999.0,
            ]),
        );

        $code = (string) (AcumaticaPayload::value($record, 'CustomerID') ?? AcumaticaPayload::recordId($record) ?? '');

        if ($code !== '') {
            $customer->set(CustomFieldEnum::CUSTOMER_ID->value, $code);
        }

        return $code;
    }

    private function customerOrg(): ?Organization
    {
        if ($this->invoice->customer_organization_id === null) {
            return null;
        }

        return Organization::query()->where('id', $this->invoice->customer_organization_id)->first();
    }
}
