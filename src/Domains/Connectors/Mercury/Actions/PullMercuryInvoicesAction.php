<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Mercury\DataTransferObject\MercuryInvoice;
use Kanvas\Connectors\Mercury\Enums\CustomFieldEnum;
use Kanvas\Connectors\Mercury\Enums\InvoiceStatusEnum;
use Kanvas\Connectors\Mercury\Services\MercuryInvoiceService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Spatie\LaravelData\DataCollection;

/**
 * Brings Mercury AR invoices into Scribe.
 *
 * Two very different jobs, and keeping them apart is the whole point:
 *
 * 1. **Invoices WE pushed** — already exist in Scribe, already booked. We only record Mercury's status
 *    (Unpaid → Paid) for visibility, so nobody chases a customer who has already paid. **No journal entry.**
 *    The actual settlement happens when the cash lands in the bank feed and the matcher clears the invoice —
 *    Mercury saying "Paid" is not the same as money being in the account, and only one of those is a fact
 *    the ledger cares about.
 *
 * 2. **Invoices created IN Mercury** (someone used the Mercury UI) — these don't exist on our books at all.
 *    We mirror them into Scribe and ISSUE them, which posts DR AR / CR Revenue. That's correct: a real
 *    invoice went to a real customer, so the receivable and the revenue are real. Unlike Acumatica — where
 *    the ERP owns the GL and imports post nothing — Kanvas owns the GL here, so the entry is ours to make.
 *
 * Idempotent on `external_id`: an invoice already imported is never imported twice.
 */
class PullMercuryInvoicesAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly ?UserInterface $user = null,
        protected readonly ?MercuryInvoiceService $invoiceService = null,
    ) {
    }

    /**
     * @return array{synced: int, imported: int, skipped: int}
     */
    public function execute(): array
    {
        $service = $this->invoiceService ?? new MercuryInvoiceService($this->app, $this->company);

        $synced = 0;
        $imported = 0;
        $skipped = 0;

        foreach ($service->list() as $mercuryInvoice) {
            $ours = $this->findLinkedInvoice($mercuryInvoice->id);

            if ($ours !== null) {
                $this->syncStatus($ours, $mercuryInvoice);
                $synced++;

                continue;
            }

            if ($this->importMercuryNative($mercuryInvoice) !== null) {
                $imported++;

                continue;
            }

            $skipped++;
        }

        return ['synced' => $synced, 'imported' => $imported, 'skipped' => $skipped];
    }

    /**
     * Records what Mercury thinks, without touching the ledger.
     *
     * Deliberately does NOT mark the Scribe invoice PAID. Mercury reporting "Paid" means it has collected —
     * the money may still be in transit. The invoice is settled when the deposit actually lands and the bank
     * matcher clears it. Jumping the gun here would credit AR against cash that hasn't arrived.
     */
    private function syncStatus(Invoice $invoice, MercuryInvoice $mercuryInvoice): void
    {
        $metadata = $invoice->metadata ?? [];
        $metadata['mercury_status'] = $mercuryInvoice->status->value;
        $metadata['mercury_status_synced_at'] = Carbon::now()->toIso8601String();

        $invoice->metadata = $metadata;
        $invoice->external_url = $mercuryInvoice->payPageUrl() ?? $invoice->external_url;
        $invoice->saveQuietly();
    }

    /**
     * Mirrors a Mercury-native invoice onto our books, issued.
     *
     * Returns null when we can't safely place it — a cancelled invoice (no receivable exists) or one whose
     * customer we can't identify (booking revenue against an unknown party is worse than skipping it and
     * saying so).
     */
    private function importMercuryNative(MercuryInvoice $mercuryInvoice): ?Invoice
    {
        if ($mercuryInvoice->status === InvoiceStatusEnum::CANCELLED) {
            return null;
        }

        $customer = $this->resolveCustomer($mercuryInvoice);

        if ($customer === null) {
            return null;
        }

        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->app,
                company: $this->company,
                billable: $customer,
                lines: $this->lines($mercuryInvoice),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                invoice_number: $mercuryInvoice->invoiceNumber,
                issued_date: $mercuryInvoice->invoiceDate ?? Carbon::now(),
                due_date: $mercuryInvoice->dueDate,
                source: 'mercury',
                external_id: $mercuryInvoice->id,
                external_url: $mercuryInvoice->payPageUrl(),
            ),
            user: $this->user,
        )->execute();

        $issued = new IssueInvoiceAction(
            invoice: $draft,
            billable: $customer,
            user: $this->user,
        )->execute();

        $issued->set(CustomFieldEnum::INVOICE_ID->value, $mercuryInvoice->id);

        return $issued;
    }

    /**
     * Mercury's line items where it gives them; otherwise a single line for the invoice total. A one-line
     * fallback keeps the AR figure correct even if the breakdown is missing — the number that matters for
     * the books is what the customer owes.
     */
    private function lines(MercuryInvoice $mercuryInvoice): DataCollection
    {
        $items = (array) ($mercuryInvoice->raw['lineItems'] ?? []);

        if ($items === []) {
            return new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(
                    description: 'Mercury invoice ' . ($mercuryInvoice->invoiceNumber ?? $mercuryInvoice->id),
                    quantity: 1,
                    unit_price_native: $mercuryInvoice->amount,
                ),
            ]);
        }

        return new DataCollection(InvoiceLineData::class, array_map(
            fn (array $item): InvoiceLineData => new InvoiceLineData(
                description: (string) ($item['name'] ?? 'Line item'),
                quantity: (float) ($item['quantity'] ?? 1),
                unit_price_native: (float) ($item['unitPrice'] ?? 0),
                tax_rate: isset($item['salesTaxRate']) ? (float) $item['salesTaxRate'] : null,
            ),
            $items,
        ));
    }

    /**
     * `external_id` finds the ones we imported; the custom field finds the ones we pushed.
     */
    private function findLinkedInvoice(string $mercuryInvoiceId): ?Invoice
    {
        return Invoice::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where('external_id', $mercuryInvoiceId)
            ->first()
            ?? $this->findInvoiceByMercuryId($mercuryInvoiceId);
    }

    private function resolveCustomer(MercuryInvoice $mercuryInvoice): ?Organization
    {
        if ($mercuryInvoice->customerId === null) {
            return null;
        }

        /** @var Organization|null $organization */
        $organization = Organization::getByCustomFieldBuilder(
            CustomFieldEnum::CUSTOMER_ID->value,
            $mercuryInvoice->customerId,
            $this->company,
        )
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        return $organization;
    }

    private function findInvoiceByMercuryId(string $mercuryInvoiceId): ?Invoice
    {
        /** @var Invoice|null $invoice */
        $invoice = Invoice::getByCustomFieldBuilder(
            CustomFieldEnum::INVOICE_ID->value,
            $mercuryInvoiceId,
            $this->company,
        )
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->first();

        return $invoice;
    }
}
