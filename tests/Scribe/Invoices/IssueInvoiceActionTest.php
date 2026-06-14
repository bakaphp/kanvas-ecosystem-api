<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\Invoices\Stubs\StubBillable;
use Tests\TestCase;

/**
 * End-to-end: draft invoice → issue → verify JE shape (DR AR / CR Revenue / CR Tax Payable)
 * + frozen snapshot + auto-allocated invoice_number.
 */
class IssueInvoiceActionTest extends TestCase
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

    public function test_issue_action_freezes_snapshot_allocates_number_and_posts_balanced_je(): void
    {
        $billable = new StubBillable(
            id: 4711,
            type: 'organization',
            displayName: 'ACME Corp',
            legalName: 'ACME Corporation Limited',
            taxId: '123-45678-9',
            email: 'ap@acme.do',
            address: ['street' => '101 Main St', 'city' => 'Santo Domingo', 'country' => 'DO'],
        );

        // Create a draft invoice with one line: 10 hours × $100 = $1000 subtotal + $180 tax = $1180 total.
        $invoice = new CreateInvoiceAction(
            data: $this->makeInvoiceData(
                billable: $billable,
                currency: 'USD',
                fxRate: 1.0,
                netTermsDays: 30,
                issuedDate: Carbon::parse('2026-06-15'),
                lines: [
                    new InvoiceLineData(
                        description: 'Software consulting — June 2026',
                        quantity: 10,
                        unit_price_native: 100.00,
                        tax_rate: 0.18,
                        tax_amount_native: 180.00,
                    ),
                ],
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $invoice->document_status, 'Starts as draft.');
        $this->assertNull($invoice->invoice_number, 'Number not yet allocated for draft.');
        $this->assertNull($invoice->billable_display_name, 'Snapshot not yet frozen for draft.');

        // Issue it
        $issued = new IssueInvoiceAction(
            invoice: $invoice,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();

        // Status flipped, collection state set
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $issued->document_status);
        $this->assertSame(InvoiceCollectionStateEnum::CURRENT, $issued->collection_state);

        // Snapshot frozen
        $this->assertSame('ACME Corp', $issued->billable_display_name);
        $this->assertSame('123-45678-9', $issued->billable_tax_id);
        $this->assertSame('ap@acme.do', $issued->billable_email);
        $this->assertIsArray($issued->billing_address_snapshot);
        $this->assertSame('Santo Domingo', $issued->billing_address_snapshot['city']);

        // Number allocated
        $this->assertNotNull($issued->invoice_number);
        $this->assertSame('1', $issued->invoice_number, 'First invoice for the company gets number "1".');

        // Due date computed from net_terms_days
        $this->assertSame('2026-07-15', $issued->due_date->toDateString(), 'due_date = issued_date + 30 days.');

        // JE posted
        $je = JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', 'invoice')
            ->where('source_id', $issued->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->first();

        $this->assertNotNull($je, 'A JE should have been posted for the issue.');

        $je->load('lines');
        $this->assertCount(3, $je->lines, 'JE should have 3 lines: DR AR / CR Revenue / CR Tax Payable.');

        // Balanced
        $totalDebit = $je->lines->sum('debit_base');
        $totalCredit = $je->lines->sum('credit_base');
        $this->assertEquals($totalDebit, $totalCredit, 'JE balanced.');
        $this->assertEquals(1180.0, $totalDebit, 'Total debit = invoice total.');

        // Verify each line points at the right account
        $arAccountId = Account::query()->where('account_sub_type', AccountSubTypeEnum::ACCOUNTS_RECEIVABLE->value)
            ->where('companies_id', $this->company->getId())->first()?->id;
        $revenueAccountId = Account::query()->where('account_sub_type', AccountSubTypeEnum::SERVICE_REVENUE->value)
            ->where('companies_id', $this->company->getId())->first()?->id;
        $taxAccountId = Account::query()->where('account_sub_type', AccountSubTypeEnum::SALES_TAX_PAYABLE->value)
            ->where('companies_id', $this->company->getId())->first()?->id;

        $arLine = $je->lines->firstWhere('account_id', $arAccountId);
        $this->assertNotNull($arLine);
        $this->assertEquals(1180.0, (float) $arLine->debit_native, 'AR debit = total.');

        $revLine = $je->lines->firstWhere('account_id', $revenueAccountId);
        $this->assertNotNull($revLine);
        $this->assertEquals(1000.0, (float) $revLine->credit_native, 'Revenue credit = subtotal (no discount).');

        $taxLine = $je->lines->firstWhere('account_id', $taxAccountId);
        $this->assertNotNull($taxLine);
        $this->assertEquals(180.0, (float) $taxLine->credit_native, 'Tax payable credit = tax amount.');
    }

    public function test_re_issuing_already_issued_invoice_is_idempotent(): void
    {
        $billable = new StubBillable();

        $invoice = new CreateInvoiceAction(
            data: $this->makeInvoiceData(
                billable: $billable,
                lines: [new InvoiceLineData(
                    description: 'Test',
                    quantity: 1,
                    unit_price_native: 100,
                )],
                issuedDate: Carbon::parse('2026-06-15'),
            ),
            user: static::$cachedUser,
        )->execute();

        $first = new IssueInvoiceAction($invoice, $billable, static::$cachedUser)->execute();
        $firstNumber = $first->invoice_number;
        $firstJeCount = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count();

        // Re-issue — should be no-op
        $second = new IssueInvoiceAction($first, $billable, static::$cachedUser)->execute();
        $secondJeCount = JournalEntry::query()->where('source_type', 'invoice')->where('source_id', $invoice->id)->count();

        $this->assertSame($firstNumber, $second->invoice_number, 'Number stays the same on idempotent re-issue.');
        $this->assertSame($firstJeCount, $secondJeCount, 'No new JE posted on idempotent re-issue.');
    }

    /**
     * @param array<int, InvoiceLineData> $lines
     */
    private function makeInvoiceData(
        StubBillable $billable,
        array $lines,
        string $currency = 'USD',
        float $fxRate = 1.0,
        int $netTermsDays = 30,
        ?Carbon $issuedDate = null,
    ): InvoiceData {
        return new InvoiceData(
            app: $this->kanvasApp,
            company: $this->company,
            billable: $billable,
            lines: new DataCollection(InvoiceLineData::class, $lines),
            currency: $currency,
            fx_rate_to_base: $fxRate,
            net_terms_days: $netTermsDays,
            issued_date: $issuedDate,
        );
    }
}
