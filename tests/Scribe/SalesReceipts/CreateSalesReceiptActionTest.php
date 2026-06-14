<?php

declare(strict_types=1);

namespace Tests\Scribe\SalesReceipts;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\SalesReceipts\Actions\CreateSalesReceiptAction;
use Kanvas\Scribe\SalesReceipts\Actions\VoidSalesReceiptAction;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceiptData;
use Kanvas\Scribe\SalesReceipts\DataTransferObject\SalesReceiptLineData;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\Invoices\Stubs\StubBillable;
use Tests\TestCase;

/**
 * End-to-end:
 *   - CreateSalesReceiptAction posts a balanced JE (DR Cash / CR Revenue / CR Tax) — no AR intermediate
 *   - billable snapshot frozen at creation (no draft phase)
 *   - VoidSalesReceiptAction mirror-JE nets the original to zero
 */
class CreateSalesReceiptActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    public function test_create_action_writes_receipt_freezes_snapshot_and_posts_balanced_je(): void
    {
        $billable = new StubBillable(displayName: 'QuickShop LLC');

        // QuickShop buys a $49 SaaS Pro Plan — no tax for simplicity
        $receipt = new CreateSalesReceiptAction(
            data: new SalesReceiptData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(SalesReceiptLineData::class, [
                    new SalesReceiptLineData(
                        description: 'Pro Plan — 1 month',
                        quantity: 1,
                        unit_price_native: 49.00,
                    ),
                ]),
                receipt_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();

        // State + snapshot
        $this->assertSame(SalesReceiptStatusEnum::RECORDED, $receipt->status);
        $this->assertSame('QuickShop LLC', $receipt->billable_display_name, 'Snapshot frozen on creation.');
        $this->assertSame('1', $receipt->receipt_number, 'First sales receipt allocated number "1".');
        $this->assertEquals(49.0, (float) $receipt->total_native);

        // JE shape: DR Cash $49 / CR Revenue $49 (no tax line)
        $je = JournalEntry::query()
            ->where('source_type', 'sales_receipt')
            ->where('source_id', $receipt->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->first();

        $this->assertNotNull($je);
        $je->load('lines');
        $this->assertCount(2, $je->lines, 'JE should have 2 lines: DR Cash / CR Revenue (no tax).');

        $totalDebit = $je->lines->sum('debit_base');
        $totalCredit = $je->lines->sum('credit_base');
        $this->assertEquals($totalDebit, $totalCredit, 'JE balanced.');
        $this->assertEquals(49.0, $totalDebit);

        $cashAccountId = $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING);
        $revenueAccountId = $this->accountIdBySubType(AccountSubTypeEnum::SERVICE_REVENUE);

        $cashLine = $je->lines->firstWhere('account_id', $cashAccountId);
        $this->assertNotNull($cashLine, 'Cash line should reference system CASH_CHECKING account.');
        $this->assertEquals(49.0, (float) $cashLine->debit_native);

        $revLine = $je->lines->firstWhere('account_id', $revenueAccountId);
        $this->assertNotNull($revLine);
        $this->assertEquals(49.0, (float) $revLine->credit_native);
    }

    public function test_create_with_tax_includes_three_je_lines(): void
    {
        $billable = new StubBillable();

        // $100 product + 18% tax = $118
        $receipt = new CreateSalesReceiptAction(
            data: new SalesReceiptData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(SalesReceiptLineData::class, [
                    new SalesReceiptLineData(
                        description: 'Tax-inclusive product',
                        quantity: 1,
                        unit_price_native: 100.00,
                        tax_rate: 0.18,
                        tax_amount_native: 18.00,
                    ),
                ]),
                receipt_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();

        $je = JournalEntry::query()
            ->where('source_type', 'sales_receipt')
            ->where('source_id', $receipt->id)
            ->first();

        $je->load('lines');
        $this->assertCount(3, $je->lines, 'JE should have 3 lines including the tax payable.');
        $this->assertEquals(118.0, $je->lines->sum('debit_base'));
        $this->assertEquals(118.0, $je->lines->sum('credit_base'));

        $taxAccountId = $this->accountIdBySubType(AccountSubTypeEnum::SALES_TAX_PAYABLE);
        $taxLine = $je->lines->firstWhere('account_id', $taxAccountId);
        $this->assertNotNull($taxLine);
        $this->assertEquals(18.0, (float) $taxLine->credit_native);
    }

    public function test_void_posts_mirror_je_and_marks_original_reversed(): void
    {
        $billable = new StubBillable();

        $receipt = new CreateSalesReceiptAction(
            data: new SalesReceiptData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(SalesReceiptLineData::class, [
                    new SalesReceiptLineData(
                        description: 'Cancelled item',
                        quantity: 1,
                        unit_price_native: 49.00,
                    ),
                ]),
                receipt_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();

        $originalJe = JournalEntry::query()
            ->where('source_type', 'sales_receipt')
            ->where('source_id', $receipt->id)
            ->whereNull('is_reversal_of')
            ->first();
        $this->assertNotNull($originalJe);

        $voided = new VoidSalesReceiptAction(
            salesReceipt: $receipt,
            voidReasonCode: 'customer_refund',
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(SalesReceiptStatusEnum::VOIDED, $voided->status);
        $this->assertSame('customer_refund', $voided->void_reason_code);

        $originalJe->refresh();
        $this->assertSame(JournalEntryStatusEnum::REVERSED, $originalJe->status);

        $mirrorJe = JournalEntry::query()
            ->where('source_type', 'sales_receipt')
            ->where('source_id', $receipt->id)
            ->where('is_reversal_of', $originalJe->id)
            ->first();
        $this->assertNotNull($mirrorJe);

        $mirrorJe->load('lines');
        $originalJe->load('lines');

        $netByAccount = [];
        foreach ($originalJe->lines as $line) {
            $netByAccount[$line->account_id] = ($netByAccount[$line->account_id] ?? 0)
                + (float) $line->debit_base - (float) $line->credit_base;
        }
        foreach ($mirrorJe->lines as $line) {
            $netByAccount[$line->account_id] = ($netByAccount[$line->account_id] ?? 0)
                + (float) $line->debit_base - (float) $line->credit_base;
        }
        foreach ($netByAccount as $accountId => $net) {
            $this->assertEquals(0.0, $net, "Account {$accountId} should net to zero after void.");
        }
    }

    private function accountIdBySubType(AccountSubTypeEnum $subType): int
    {
        $row = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', $subType->value)
            ->first();
        $this->assertNotNull($row);

        return (int) $row->id;
    }
}
