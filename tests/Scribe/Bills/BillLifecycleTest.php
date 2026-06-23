<?php

declare(strict_types=1);

namespace Tests\Scribe\Bills;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\CreateBillAction;
use Kanvas\Scribe\Bills\Actions\MarkBillPaidAction;
use Kanvas\Scribe\Bills\Actions\ReceiveBillAction;
use Kanvas\Scribe\Bills\Actions\UpdateBillAction;
use Kanvas\Scribe\Bills\Actions\VoidBillAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\PdfIngest\Actions\BackfillPdfIngestedBillsAction;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Coverage for PR 10 — Bills sub-ledger:
 *   - CreateBillAction writes DRAFT bill + lines
 *   - ReceiveBillAction freezes vendor snapshot, allocates bill_number, posts DR Expense + DR Input Tax / CR AP JE
 *   - MarkBillPaidAction recomputes from allocations, flips to PAID when balance hits zero
 *   - VoidBillAction posts mirror reversal JE
 *   - PR 9 → PR 10: vendor_invoice PDFs now route to draft Bill (not just log)
 *   - BackfillPdfIngestedBillsAction processes historical AWAITING_BILL_SUPPORT log rows
 */
class BillLifecycleTest extends TestCase
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

    public function test_snapshot_override_wins_over_org_default_on_receive(): void
    {
        $vendor = $this->seedOrganization('Default Vendor Name');

        $bill = new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Service',
                        quantity: 1,
                        unit_price_native: 500.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-15'),
                vendor_display_name: 'Anthropic Subsidiary LLC',
                vendor_legal_name: 'Anthropic, PBC',
                vendor_tax_id: '12-3456789',
                vendor_email: 'billing@anthropic.test',
            ),
            user: static::$cachedUser,
        )->execute();

        $received = new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame('Anthropic Subsidiary LLC', $received->vendor_display_name);
        $this->assertSame('Anthropic, PBC', $received->vendor_legal_name);
        $this->assertSame('12-3456789', $received->vendor_tax_id);
        $this->assertSame('billing@anthropic.test', $received->vendor_email);
        $this->assertEquals((int) $vendor->id, (int) $received->vendor_organization_id);
    }

    public function test_snapshot_falls_back_to_vendor_org_when_override_not_set(): void
    {
        $vendor = $this->seedOrganization('Default Vendor Name');
        $bill = $this->createDraftBill(unitPrice: 100.0, tax: 0.0);

        $received = new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame('Default Vendor Name', $received->vendor_display_name);
        $this->assertSame('Default Vendor Name', $received->vendor_legal_name);
    }

    public function test_update_draft_bill_swaps_vendor_and_replaces_lines(): void
    {
        // Primary use case: PDF-ingested drafts arrive without a resolved vendor; operator (or agent)
        // attaches the vendor afterward via update before clicking Receive.
        $bill = $this->createDraftBill(unitPrice: 1500.0, tax: 0.0);
        $this->assertNull($bill->vendor_organization_id);

        $vendor = $this->seedOrganization('Anthropic');
        $expenseAccountId = $this->accountIdBySubType(AccountSubTypeEnum::SOFTWARE_SUBSCRIPTIONS);

        $updated = new UpdateBillAction(
            bill: $bill,
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'API credits',
                        quantity: 1,
                        unit_price_native: 2000.0,
                        tax_amount_native: 0.0,
                        expense_account_id: $expenseAccountId,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-15'),
                net_terms_days: 15,
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::DRAFT, $updated->document_status);
        $this->assertEquals((int) $vendor->id, (int) $updated->vendor_organization_id);
        $this->assertEquals(2000.0, (float) $updated->total_native);
        $this->assertEquals(15, (int) $updated->net_terms_days);

        // Lines fully replaced — old line gone, single new line present.
        $this->assertCount(1, $updated->lines);
        $this->assertSame('API credits', $updated->lines->first()->description);

        // No JE posted — drafts don't touch the GL.
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'bill')->where('source_id', $updated->id)->count(),
        );
    }

    public function test_update_received_bill_throws(): void
    {
        $vendor = $this->seedOrganization('Vendor');
        $bill = $this->createDraftBill(unitPrice: 500.0, tax: 0.0);
        $received = new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $this->expectException(InvalidBillTransitionException::class);

        new UpdateBillAction(
            bill: $received,
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: $vendor,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Should not be allowed',
                        quantity: 1,
                        unit_price_native: 999.0,
                        tax_amount_native: 0.0,
                        expense_account_id: $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING),
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_receive_bill_posts_balanced_dr_expense_cr_ap_je(): void
    {
        $vendor = $this->seedOrganization('Vendor');
        $bill = $this->createDraftBill(unitPrice: 2000.0, tax: 0.0);

        $received = new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::RECEIVED, $received->document_status);
        $this->assertSame($vendor->name, $received->vendor_display_name);
        $this->assertSame($vendor->name, $received->vendor_legal_name);
        $this->assertNull(
            $received->vendor_tax_id,
            'Org has no tax_id custom field — vendor_tax_id snapshot is null.',
        );
        $this->assertNotNull($received->bill_number, 'Bill number must be allocated on receive');
        $this->assertNotNull($received->received_date);
        $this->assertNotNull($received->due_date);

        // JE posted, balanced
        $je = JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $received->id)
            ->first();
        $this->assertNotNull($je);
        $je->load('lines');
        $this->assertEquals(2000.0, $je->lines->sum('debit_base'));
        $this->assertEquals(2000.0, $je->lines->sum('credit_base'));

        // Specific account routing
        $apId = $this->accountIdBySubType(AccountSubTypeEnum::ACCOUNTS_PAYABLE);
        $apLine = $je->lines->firstWhere('account_id', $apId);
        $this->assertNotNull($apLine);
        $this->assertEquals(2000.0, (float) $apLine->credit_native);
    }

    public function test_receive_bill_with_input_tax_credits_dr_input_tax_account(): void
    {
        $vendor = $this->seedOrganization('Vendor');
        $bill = $this->createDraftBill(unitPrice: 1000.0, tax: 180.0);

        new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $je = JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $bill->id)
            ->first();
        $je->load('lines');

        // Three lines: DR Expense, DR Input Tax, CR AP
        $this->assertCount(3, $je->lines);
        $this->assertEquals(1180.0, $je->lines->sum('debit_base'));
        $this->assertEquals(1180.0, $je->lines->sum('credit_base'));

        $inputTaxId = $this->accountIdBySubType(AccountSubTypeEnum::INPUT_TAX_RECEIVABLE);
        $taxLine = $je->lines->firstWhere('account_id', $inputTaxId);
        $this->assertNotNull($taxLine, 'DR Input Tax Receivable line missing');
        $this->assertEquals(180.0, (float) $taxLine->debit_native);
    }

    public function test_mark_bill_paid_via_allocation_flips_status(): void
    {
        $vendor = $this->seedOrganization('Vendor');
        $bill = $this->createDraftBill(unitPrice: 500.0, tax: 0.0);

        $received = new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        // Allocate a payment manually for the test
        $allocation = new BillPaymentAllocation();
        $allocation->apps_id = $received->apps_id;
        $allocation->companies_id = $received->companies_id;
        $allocation->bill_id = $received->id;
        $allocation->payment_id = null;
        $allocation->source_type = AllocationSourceTypeEnum::PAYMENT->value;
        $allocation->status = AllocationStatusEnum::ACTIVE->value;
        $allocation->amount_native = 500.0;
        $allocation->amount_base = 500.0;
        $allocation->currency = 'USD';
        $allocation->fx_rate_to_base = 1.0;
        $allocation->allocated_at = Carbon::now();
        $allocation->source = 'kanvas';
        $allocation->save();

        $paid = new MarkBillPaidAction(bill: $received, user: static::$cachedUser)->execute();

        $this->assertSame(BillDocumentStatusEnum::PAID, $paid->document_status);
        $this->assertEquals(500.0, (float) $paid->paid_native);
        $this->assertEquals(0.0, (float) $paid->balance_due_native);
    }

    public function test_void_received_bill_posts_mirror_reversal(): void
    {
        $vendor = $this->seedOrganization('Vendor');
        $bill = $this->createDraftBill(unitPrice: 750.0, tax: 0.0);
        $received = new ReceiveBillAction(
            bill: $bill,
            vendor: $vendor,
            user: static::$cachedUser,
        )->execute();

        $voided = new VoidBillAction(
            bill: $received,
            voidReasonCode: 'duplicate_send',
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(BillDocumentStatusEnum::VOIDED, $voided->document_status);
        $this->assertNotNull($voided->voided_at);

        $original = JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $voided->id)
            ->whereNull('is_reversal_of')
            ->first();
        $this->assertSame(JournalEntryStatusEnum::REVERSED, $original->status);

        $reversal = JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $voided->id)
            ->whereNotNull('is_reversal_of')
            ->first();
        $this->assertNotNull($reversal);

        // Net across both JEs is zero per account
        $original->load('lines');
        $reversal->load('lines');
        $netByAccount = [];
        foreach ([$original, $reversal] as $entry) {
            foreach ($entry->lines as $line) {
                $netByAccount[$line->account_id] = ($netByAccount[$line->account_id] ?? 0.0)
                    + (float) $line->debit_base - (float) $line->credit_base;
            }
        }
        foreach ($netByAccount as $accountId => $net) {
            $this->assertEqualsWithDelta(0.0, $net, 0.0001, "Account {$accountId} should net to zero.");
        }
    }

    public function test_void_draft_bill_throws(): void
    {
        $bill = $this->createDraftBill(unitPrice: 100.0, tax: 0.0);

        $this->expectException(InvalidBillTransitionException::class);

        new VoidBillAction(
            bill: $bill,
            voidReasonCode: 'mistake',
            user: static::$cachedUser,
        )->execute();
    }

    public function test_backfill_action_promotes_historical_awaiting_bill_support_logs(): void
    {
        // Seed an old log row from the PR 9 era — status=AWAITING_BILL_SUPPORT, no linked entity
        $pdf = $this->createFilesystemRow();
        $log = new PdfIngestLog();
        $log->apps_id = $this->kanvasApp->getId();
        $log->companies_id = $this->company->getId();
        $log->filesystem_id = (int) $pdf->getKey();
        $log->document_type = PdfIngestDocumentTypeEnum::VENDOR_INVOICE;
        $log->confidence = 0.93;
        $log->status = PdfIngestStatusEnum::AWAITING_BILL_SUPPORT;
        $log->rejected_reason = 'Vendor invoice received — Bill sub-ledger ships in PR 10.';
        $log->extracted_payload = [
            'vendor_name' => 'Datadog',
            'issue_date' => '2026-06-15',
            'due_date' => '2026-07-15',
            'currency' => 'USD',
            'subtotal' => 800.0,
            'tax' => 0.0,
            'total' => 800.0,
        ];
        $log->save();

        $stats = new BackfillPdfIngestedBillsAction(
            app: $this->kanvasApp,
            company: $this->company,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(1, $stats['candidates']);
        $this->assertSame(1, $stats['backfilled']);

        $log->refresh();
        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame('bill', $log->linked_entity_type);
        $this->assertNotNull($log->linked_entity_id);

        $bill = Bill::query()->where('id', $log->linked_entity_id)->first();
        $this->assertNotNull($bill);
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertEquals(800.0, (float) $bill->total_native);
        $this->assertSame('Datadog', $bill->vendor_display_name);
    }

    private function createDraftBill(float $unitPrice, float $tax): Bill
    {
        $expenseAccountId = $this->accountIdBySubType(AccountSubTypeEnum::CLOUD_HOSTING);

        return new CreateBillAction(
            data: new BillData(
                app: $this->kanvasApp,
                company: $this->company,
                vendor: null,
                lines: new DataCollection(BillLineData::class, [
                    new BillLineData(
                        description: 'Cloud services subscription',
                        quantity: 1,
                        unit_price_native: $unitPrice,
                        tax_amount_native: $tax,
                        expense_account_id: $expenseAccountId,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                bill_date: Carbon::parse('2026-06-15'),
                net_terms_days: 30,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function createFilesystemRow(): \Kanvas\Filesystem\Models\Filesystem
    {
        $filesystem = new \Kanvas\Filesystem\Models\Filesystem();
        $filesystem->apps_id = $this->kanvasApp->getId();
        $filesystem->companies_id = $this->company->getId();
        $filesystem->users_id = static::$cachedUser->getId();
        $filesystem->name = 'datadog-' . Carbon::now()->format('YmdHis') . '.pdf';
        $filesystem->path = 'inbound/' . $filesystem->name;
        $filesystem->url = 'https://example.test/' . $filesystem->path;
        $filesystem->size = '54321';
        $filesystem->file_type = 'pdf';
        $filesystem->save();

        return $filesystem;
    }

    private function accountIdBySubType(AccountSubTypeEnum $subType): int
    {
        $row = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', $subType->value)
            ->first();
        $this->assertNotNull($row, "Expected seeded account with sub_type='{$subType->value}'.");

        return (int) $row->id;
    }

    private function seedOrganization(string $name = 'Test Org', ?string $address = null): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => $address ?? '',
            'total_employees' => 0,
        ]);
    }
}
