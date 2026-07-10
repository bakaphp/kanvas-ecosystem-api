<?php

declare(strict_types=1);

namespace Tests\Scribe\Bills;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Actions\ImportBillFromExternalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillCollectionStateEnum;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Importing an externally-recorded bill lands a terminal-state document for AP aging WITHOUT
 * posting a JE (the external GL is imported separately, so re-posting would double-count AP).
 */
class ImportBillFromExternalActionTest extends ScribeTestCase
{
    private function billData(
        float $subtotal = 800.0,
        string $externalId = 'AP-0001',
        ?Carbon $dueDate = null,
    ): BillData {
        return new BillData(
            app: $this->kanvasApp,
            company: $this->company,
            vendor: $this->seedTestOrganization('Globex Supply'),
            lines: new DataCollection(BillLineData::class, [
                new BillLineData(
                    description: 'Imported bill',
                    quantity: 1,
                    unit_price_native: $subtotal,
                ),
            ]),
            currency: 'USD',
            fx_rate_to_base: 1.0,
            bill_number: $externalId,
            bill_date: Carbon::parse('2026-06-10'),
            due_date: $dueDate ?? Carbon::parse('2026-06-25'),
            source: 'acumatica',
            external_id: $externalId,
            origin: JournalEntryOriginEnum::EXTERNAL,
        );
    }

    private function assertNoJournalEntry(int $billId): void
    {
        $count = JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $billId)
            ->count();
        $this->assertSame(0, $count, 'Import must not post a journal entry.');
    }

    public function test_import_open_bill_lands_received_current_with_no_je(): void
    {
        $bill = new ImportBillFromExternalAction(
            data: $this->billData(subtotal: 800.0),
            paidNative: 0.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $bill->document_status);
        $this->assertSame(BillCollectionStateEnum::CURRENT, $bill->collection_state);
        $this->assertSame(JournalEntryOriginEnum::EXTERNAL, $bill->origin);
        $this->assertEquals(800.0, (float) $bill->total_native);
        $this->assertEquals(800.0, (float) $bill->balance_due_native);
        $this->assertSame('Globex Supply', $bill->vendor_display_name);
        $this->assertNotNull($bill->vendor_organization_id);
        $this->assertNoJournalEntry($bill->id);
    }

    public function test_import_fully_paid_bill_lands_paid_with_zero_balance(): void
    {
        $bill = new ImportBillFromExternalAction(
            data: $this->billData(subtotal: 250.0),
            paidNative: 250.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::PAID, $bill->document_status);
        $this->assertNull($bill->collection_state);
        $this->assertEquals(0.0, (float) $bill->balance_due_native);
        $this->assertNotNull($bill->paid_at);
        $this->assertNoJournalEntry($bill->id);
    }

    public function test_past_due_open_bill_is_overdue(): void
    {
        $bill = new ImportBillFromExternalAction(
            data: $this->billData(subtotal: 300.0, dueDate: Carbon::parse('2026-05-15')),
            paidNative: 0.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillCollectionStateEnum::OVERDUE, $bill->collection_state);
    }

    public function test_reimport_updates_balance_without_duplicating_or_posting(): void
    {
        $first = new ImportBillFromExternalAction(
            data: $this->billData(subtotal: 800.0),
            paidNative: 0.0,
            user: static::$cachedUser,
        )->execute();

        $second = new ImportBillFromExternalAction(
            data: $this->billData(subtotal: 800.0),
            paidNative: 300.0,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame($first->id, $second->id, 'Re-import updates the same row.');
        $this->assertEquals(500.0, (float) $second->balance_due_native);
        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $second->document_status);

        $rows = Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('external_id', 'AP-0001')
            ->count();
        $this->assertSame(1, $rows, 'No duplicate bill row on re-import.');
        $this->assertNoJournalEntry($second->id);
    }
}
