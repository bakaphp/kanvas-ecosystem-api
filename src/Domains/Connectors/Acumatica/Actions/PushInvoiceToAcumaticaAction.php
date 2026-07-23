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
use Kanvas\Workflow\Enums\IntegrationsEnum;

/**
 * Pushes a Kanvas-issued Invoice out to Acumatica as an AR Invoice (create + Release) — the AR mirror
 * of PushBillToAcumaticaAction.
 *
 * Idempotent — an invoice already carrying its ACUMATICA_INVOICE_ID custom field is not re-pushed.
 *
 * Unlike bills, InvoiceLine carries no expense/revenue account or subaccount at all (Scribe doesn't
 * model revenue-side GL coding per line), so there's no per-account subaccount derivation to run —
 * every line falls back to the tenant's ACUMATICA_DEFAULT_SUBACCOUNT unconditionally.
 *
 * IMPORTANT: PushPaymentToAcumaticaAction::arApplications() matches a payment's DocumentsToApply
 * against the Kanvas invoice's OWN `invoice_number` field, not a separately-tracked Acumatica
 * reference. For a payment applied later to actually match in Acumatica, the caller MUST overwrite
 * the Kanvas invoice's `invoice_number` with the ReferenceNbr this action returns, once it returns,
 * before allocating/pushing a payment against it.
 */
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

    /**
     * OData filter to adopt an Acumatica invoice this push may have already created on a prior,
     * partially failed attempt.
     *
     * Disabled for now: "CustomerRef" is writable on Invoice but is not a valid $filter-able EDM
     * property on this endpoint version — attempting it throws a raw KeyNotFoundException deep in
     * Acumatica's OData filter binder (confirmed against a live push), not a clean 400. Skipping the
     * pre-check just means a retry-after-partial-failure creates a second invoice instead of adopting
     * the first — acceptable until a real filterable field is confirmed.
     *
     * @return array<string, mixed>|null
     */
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
            'Type' => 'Invoice',
            'Customer' => $customerCode,
            'CustomerRef' => $this->invoice->invoice_number,
            'Description' => $this->invoice->notes ?? ('Kanvas invoice ' . $this->invoice->invoice_number),
            'Date' => $this->invoice->issued_date?->toDateString(),
            'DueDate' => $this->invoice->due_date?->toDateString(),
            'CurrencyID' => $this->invoice->currency,
            // A bare API-created customer (no Customer Class) has no default Location/Terms for
            // Acumatica to derive — without these, the invoice comes back with "Location cannot be
            // empty" / "Terms cannot be empty" (confirmed against a live push). MAIN + NET60 mirror
            // the defaults this tenant already uses elsewhere (e.g. the AP vendor side).
            'LocationID' => 'MAIN',
            'Terms' => 'NET60',
            'Hold' => false,
            // This tenant's credit verification intermittently flags brand-new customers into Credit
            // Hold regardless of CreditLimit (confirmed against live pushes — some invoices for the
            // same customer at the same limit landed Open, others Credit Hold). Explicitly clearing it
            // here is the reliable override, matching the manual checkbox on the Invoice screen.
            'CreditHold' => false,
        ]);

        $header['Details'] = $this->buildLines();

        return $header;
    }

    /**
     * @return array<int, array<string, array{value: mixed}>>
     */
    private function buildLines(): array
    {
        $lines = [];
        $defaultSubaccount = (string) $this->app->get(ConfigurationEnum::ACUMATICA_DEFAULT_SUBACCOUNT->value);

        foreach ($this->invoice->lines as $line) {
            /** @var InvoiceLine $line */
            $lines[] = AcumaticaPayload::wrap([
                'Description' => $line->description,
                'Qty' => (float) $line->quantity,
                'UnitPrice' => (float) $line->unit_price_native,
                'Subaccount' => $defaultSubaccount !== '' ? $defaultSubaccount : null,
            ]);
        }

        return $lines;
    }

    /**
     * Find-or-create the customer in Acumatica (push path). Creates the ERP customer lazily when the
     * org has no code yet — only reached on a real push, so a draft invoice never spawns a junk
     * customer. Mirrors EnsureAcumaticaVendorAction's shape for the AP side.
     */
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
                // A bare API-created customer with CreditLimit=0 lands every invoice in Credit Hold,
                // and Release doesn't handle that gracefully (confirmed against a live push) — give
                // new customers enough headroom that a normal invoice never trips the hold.
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
