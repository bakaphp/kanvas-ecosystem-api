<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\ImportInvoiceFromExternalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Importing an externally-issued invoice lands a terminal-state document for AR aging WITHOUT
 * posting a JE (the external GL is imported separately, so re-posting would double-count AR).
 */
class ImportInvoiceFromExternalActionTest extends ScribeTestCase
{
    private function invoiceData(
        float $subtotal = 1000.0,
        float $tax = 0.0,
        string $externalId = 'AR-0001',
        ?Carbon $dueDate = null,
    ): InvoiceData {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: $this->seedTestOrganization('ACME Corp'),
            lines: new DataCollection(InvoiceLineData::class, [
                new InvoiceLineData(
                    description: 'Imported invoice',
                    quantity: 1,
                    unit_price_native: $subtotal,
                    tax_amount_native: $tax,
                ),
            ]),
            currency: 'USD',
            fx_rate_to_base: 1.0,
            invoice_number: $externalId,
            issued_date: Carbon::parse('2026-06-10'),
            due_date: $dueDate ?? Carbon::parse('2026-06-25'),
            source: 'acumatica',
            external_id: $externalId,
            origin: JournalEntryOriginEnum::EXTERNAL,
        );
    }

    private function assertNoJournalEntry(int $invoiceId): void
    {
        $count = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoiceId)
            ->count();
        $this->assertSame(0, $count, 'Import must not post a journal entry.');
    }

    public function test_import_open_invoice_lands_issued_current_with_no_je(): void
    {
        $invoice = new ImportInvoiceFromExternalAction(
            data: $this->invoiceData(subtotal: 1000.0, tax: 180.0),
            paidNative: 0.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertSame(InvoiceCollectionStateEnum::CURRENT, $invoice->collection_state);
        $this->assertSame(JournalEntryOriginEnum::EXTERNAL, $invoice->origin);
        $this->assertEquals(1180.0, (float) $invoice->total_native);
        $this->assertEquals(1180.0, (float) $invoice->balance_due_native);
        $this->assertEquals(0.0, (float) $invoice->paid_native);
        $this->assertSame('ACME Corp', $invoice->billable_display_name);
        $this->assertNotNull($invoice->customer_organization_id);
        $this->assertNoJournalEntry($invoice->id);
    }

    public function test_import_fully_paid_invoice_lands_paid_with_zero_balance(): void
    {
        $invoice = new ImportInvoiceFromExternalAction(
            data: $this->invoiceData(subtotal: 500.0),
            paidNative: 500.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $invoice->document_status);
        $this->assertNull($invoice->collection_state);
        $this->assertEquals(0.0, (float) $invoice->balance_due_native);
        $this->assertEquals(500.0, (float) $invoice->paid_native);
        $this->assertNotNull($invoice->paid_at);
        $this->assertNoJournalEntry($invoice->id);
    }

    public function test_past_due_open_invoice_is_overdue(): void
    {
        $invoice = new ImportInvoiceFromExternalAction(
            data: $this->invoiceData(subtotal: 300.0, dueDate: Carbon::parse('2026-05-15')),
            paidNative: 0.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceCollectionStateEnum::OVERDUE, $invoice->collection_state);
    }

    public function test_reimport_updates_balance_without_duplicating_or_posting(): void
    {
        $first = new ImportInvoiceFromExternalAction(
            data: $this->invoiceData(subtotal: 1000.0),
            paidNative: 0.0,
            user: static::$cachedUser,
        )->execute();

        $second = new ImportInvoiceFromExternalAction(
            data: $this->invoiceData(subtotal: 1000.0),
            paidNative: 400.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame($first->id, $second->id, 'Re-import updates the same row.');
        $this->assertEquals(600.0, (float) $second->balance_due_native);
        $this->assertEquals(400.0, (float) $second->paid_native);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $second->document_status);

        $rows = Invoice::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('external_id', 'AR-0001')
            ->count();
        $this->assertSame(1, $rows, 'No duplicate invoice row on re-import.');
        $this->assertNoJournalEntry($second->id);
    }
}
