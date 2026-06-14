<?php

declare(strict_types=1);

namespace Tests\Scribe;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Scribe\Invoices\Actions\AmendInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\UpdateInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Items\Actions\CreateItemAction;
use Kanvas\Scribe\Items\Actions\UpdateItemAction;
use Kanvas\Scribe\Items\DataTransferObject\ItemData;
use Kanvas\Scribe\Items\Enums\ItemTypeEnum;
use Kanvas\Scribe\Items\Models\Item;
use Kanvas\Scribe\Ledger\Actions\CloseFiscalPeriodAction;
use Kanvas\Scribe\Ledger\Actions\CreateAccountAction;
use Kanvas\Scribe\Ledger\Actions\OpenFiscalPeriodAction;
use Kanvas\Scribe\Ledger\Actions\ReopenFiscalPeriodAction;
use Kanvas\Scribe\Ledger\Actions\UpdateAccountAction;
use Kanvas\Scribe\Ledger\DataTransferObject\AccountData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\PaymentTerms\Actions\CreatePaymentTermAction;
use Kanvas\Scribe\PaymentTerms\Actions\UpdatePaymentTermAction;
use Kanvas\Scribe\PaymentTerms\DataTransferObject\PaymentTermData;
use Kanvas\Scribe\PaymentTerms\Models\PaymentTerm;
use Kanvas\Scribe\TaxCodes\Actions\CreateTaxCodeAction;
use Kanvas\Scribe\TaxCodes\Actions\UpdateTaxCodeAction;
use Kanvas\Scribe\TaxCodes\DataTransferObject\TaxCodeData;
use Kanvas\Scribe\TaxCodes\DataTransferObject\TaxRateData;
use Kanvas\Scribe\TaxCodes\Models\TaxCode;
use RuntimeException;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\Invoices\Stubs\StubBillable;
use Tests\TestCase;

/**
 * Coverage for PR 5.6 additions:
 *   - UpdateInvoiceAction (draft-only)
 *   - IssueCreditNoteAction (parent invoice → credit note + allocation + recompute)
 *   - AmendInvoiceAction (post-issue mutator + diff history)
 *   - OpenFiscalPeriodAction / CloseFiscalPeriodAction / ReopenFiscalPeriodAction
 *   - Master-data CRUD: Create/Update for Account, Item, TaxCode, PaymentTerm
 *   - ReverseJournalEntryAction behavior via refactored VoidInvoice/SalesReceipt/Expense (regression covered
 *     by the existing Void tests)
 */
class ScribeFollowUpActionsTest extends TestCase
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

    public function test_update_draft_invoice_replaces_lines_and_recomputes_totals(): void
    {
        $billable = new StubBillable();
        $draft = $this->createDraftInvoice($billable, unitPrice: 100.0, tax: 0.0);

        $updated = new UpdateInvoiceAction(
            invoice: $draft,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'New A', quantity: 1, unit_price_native: 250.0),
                    new InvoiceLineData(description: 'New B', quantity: 1, unit_price_native: 75.0, tax_amount_native: 13.5),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                net_terms_days: 45,
                issued_date: Carbon::parse('2026-06-20'),
                notes: 'updated notes',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertEquals(338.5, (float) $updated->total_native);
        $this->assertCount(2, $updated->lines);
        $this->assertSame(45, $updated->net_terms_days);
        $this->assertSame('updated notes', $updated->notes);
    }

    public function test_update_non_draft_invoice_throws(): void
    {
        $billable = new StubBillable();
        $invoice = $this->issueTestInvoice($billable);

        $this->expectException(InvalidInvoiceTransitionException::class);
        $this->expectExceptionMessageMatches('/Only draft invoices are editable/');

        new UpdateInvoiceAction(
            invoice: $invoice,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'attempted edit', quantity: 1, unit_price_native: 1.0),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_issue_credit_note_creates_credit_note_invoice_posts_inverse_je_and_recomputes_parent(): void
    {
        $billable = new StubBillable();
        $parent = $this->issueTestInvoice($billable, subtotal: 1000.0, tax: 180.0);
        $this->assertEquals(1180.0, (float) $parent->total_native);
        $this->assertEquals(1180.0, (float) $parent->balance_due_native);

        $creditNote = new IssueCreditNoteAction(
            parentInvoice: $parent,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'Discount adjustment', quantity: 1, unit_price_native: 300.0),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                document_type: DocumentTypeEnum::CREDIT_NOTE,
                issued_date: Carbon::parse('2026-06-20'),
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(DocumentTypeEnum::CREDIT_NOTE, $creditNote->document_type);
        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $creditNote->document_status);
        $this->assertSame((int) $parent->id, (int) $creditNote->parent_invoice_id);
        $this->assertEquals(300.0, (float) $creditNote->total_native);
        $this->assertEquals(0.0, (float) $creditNote->balance_due_native);
        $this->assertNotNull($creditNote->invoice_number);

        $creditJe = JournalEntry::query()
            ->where('source_type', 'credit_note')
            ->where('source_id', $creditNote->id)
            ->first();
        $this->assertNotNull($creditJe);
        $creditJe->load('lines');
        $this->assertEquals($creditJe->lines->sum('debit_base'), $creditJe->lines->sum('credit_base'));

        $allocation = InvoicePaymentAllocation::query()
            ->where('invoice_id', $parent->id)
            ->where('source_type', 'credit_note')
            ->first();
        $this->assertNotNull($allocation);
        $this->assertEquals(300.0, (float) $allocation->amount_native);
        $this->assertSame(AllocationStatusEnum::ACTIVE, $allocation->status);

        $parent->refresh();
        $this->assertEquals(300.0, (float) $parent->paid_native);
        $this->assertEquals(880.0, (float) $parent->balance_due_native);
    }

    public function test_issue_credit_note_for_full_amount_marks_parent_paid(): void
    {
        $billable = new StubBillable();
        $parent = $this->issueTestInvoice($billable, subtotal: 500.0, tax: 0.0);

        new IssueCreditNoteAction(
            parentInvoice: $parent,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'Full credit', quantity: 1, unit_price_native: 500.0),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                document_type: DocumentTypeEnum::CREDIT_NOTE,
                issued_date: Carbon::parse('2026-06-20'),
            ),
            user: static::$cachedUser,
        )->execute();

        $parent->refresh();
        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $parent->document_status);
        $this->assertEquals(0.0, (float) $parent->balance_due_native);
    }

    public function test_issue_credit_note_rejects_amount_exceeding_parent_total(): void
    {
        $billable = new StubBillable();
        $parent = $this->issueTestInvoice($billable, subtotal: 100.0, tax: 0.0);

        $this->expectException(InvalidInvoiceTransitionException::class);
        $this->expectExceptionMessageMatches('/exceeds parent invoice total/');

        new IssueCreditNoteAction(
            parentInvoice: $parent,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'Excessive', quantity: 1, unit_price_native: 200.0),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                document_type: DocumentTypeEnum::CREDIT_NOTE,
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_amend_invoice_changes_due_date_and_appends_history(): void
    {
        $billable = new StubBillable();
        $invoice = $this->issueTestInvoice($billable);
        $originalDueDate = $invoice->due_date;

        $amended = new AmendInvoiceAction(
            invoice: $invoice,
            data: new AmendInvoiceData(
                reason: 'Customer requested 15-day extension',
                due_date: Carbon::parse('2026-08-15'),
                internal_notes: 'Approved by Maria',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame('2026-08-15', $amended->due_date->format('Y-m-d'));
        $this->assertSame('Approved by Maria', $amended->internal_notes);

        $amendments = $amended->metadata['amendments'] ?? [];
        $this->assertCount(1, $amendments);
        $this->assertSame('Customer requested 15-day extension', $amendments[0]['reason']);
        $this->assertSame(
            $originalDueDate?->format('Y-m-d'),
            $amendments[0]['changes']['due_date']['from'] ?? null,
        );
        $this->assertSame('2026-08-15', $amendments[0]['changes']['due_date']['to']);
    }

    public function test_amend_invoice_rejected_on_draft(): void
    {
        $billable = new StubBillable();
        $draft = $this->createDraftInvoice($billable);

        $this->expectException(InvalidInvoiceTransitionException::class);
        $this->expectExceptionMessageMatches('/Use UpdateInvoiceAction for drafts/');

        new AmendInvoiceAction(
            invoice: $draft,
            data: new AmendInvoiceData(reason: 'wrong path', notes: 'irrelevant'),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_open_fiscal_period_rejects_overlap_with_existing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/overlap/');

        new OpenFiscalPeriodAction(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-15'),
            periodEnd: Carbon::parse('2026-07-15'),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_open_fiscal_period_idempotent_for_exact_duplicate(): void
    {
        $existing = FiscalPeriod::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        $result = new OpenFiscalPeriodAction(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse((string) $existing->period_start->format('Y-m-d')),
            periodEnd: Carbon::parse((string) $existing->period_end->format('Y-m-d')),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame((int) $existing->id, (int) $result->id);
    }

    public function test_close_then_reopen_fiscal_period(): void
    {
        $period = FiscalPeriod::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        $closed = new CloseFiscalPeriodAction(
            period: $period,
            targetStatus: FiscalPeriodStatusEnum::SOFT_CLOSED,
            user: static::$cachedUser,
            closeNotes: 'EOM close',
        )->execute();
        $this->assertSame(FiscalPeriodStatusEnum::SOFT_CLOSED, $closed->status);
        $this->assertNotNull($closed->closed_at);

        $hard = new CloseFiscalPeriodAction(
            period: $closed,
            targetStatus: FiscalPeriodStatusEnum::HARD_CLOSED,
            user: static::$cachedUser,
        )->execute();
        $this->assertSame(FiscalPeriodStatusEnum::HARD_CLOSED, $hard->status);

        $reopened = new ReopenFiscalPeriodAction(
            period: $hard,
            user: static::$cachedUser,
            reopenNotes: 'Audit correction',
        )->execute();
        $this->assertSame(FiscalPeriodStatusEnum::OPEN, $reopened->status);
        $this->assertNull($reopened->closed_at);
    }

    public function test_close_fiscal_period_rejects_hard_to_soft_weakening(): void
    {
        $period = FiscalPeriod::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->first();

        new CloseFiscalPeriodAction(
            period: $period,
            targetStatus: FiscalPeriodStatusEnum::HARD_CLOSED,
            user: static::$cachedUser,
        )->execute();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot weaken/');

        new CloseFiscalPeriodAction(
            period: $period->refresh(),
            targetStatus: FiscalPeriodStatusEnum::SOFT_CLOSED,
            user: static::$cachedUser,
        )->execute();
    }

    public function test_create_account_validates_unique_number(): void
    {
        $first = new CreateAccountAction(
            data: new AccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_number: '90001',
                name: 'Test Asset',
                account_type: AccountTypeEnum::ASSET,
                account_sub_type: AccountSubTypeEnum::CASH_CHECKING,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();
        $this->assertInstanceOf(Account::class, $first);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/already used/');
        new CreateAccountAction(
            data: new AccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_number: '90001',
                name: 'Duplicate',
                account_type: AccountTypeEnum::ASSET,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_update_account_rejects_account_type_change(): void
    {
        $account = new CreateAccountAction(
            data: new AccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_number: '90002',
                name: 'Type test',
                account_type: AccountTypeEnum::ASSET,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Cannot change account_type/');

        new UpdateAccountAction(
            account: $account,
            data: new AccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_number: '90002',
                name: 'Same',
                account_type: AccountTypeEnum::LIABILITY,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();
    }

    public function test_update_system_account_locks_most_fields(): void
    {
        $arSystem = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('is_system', true)
            ->where('account_sub_type', AccountSubTypeEnum::ACCOUNTS_RECEIVABLE->value)
            ->first();
        $this->assertNotNull($arSystem);

        $original = $arSystem->account_number;

        $updated = new UpdateAccountAction(
            account: $arSystem,
            data: new AccountData(
                app: $this->kanvasApp,
                company: $this->company,
                account_number: 'DIFFERENT',
                name: 'New Name',
                account_type: AccountTypeEnum::from($arSystem->account_type->value),
                description: 'Updated description',
                currency: $arSystem->currency,
                is_active: false,
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame($original, $updated->account_number, 'System account_number must NOT change.');
        $this->assertFalse((bool) $updated->is_active, 'is_active is one of the few mutable fields on system accounts.');
        $this->assertSame('Updated description', $updated->description);
    }

    public function test_create_and_update_item(): void
    {
        $income = $this->accountIdBySubType(AccountSubTypeEnum::SERVICE_REVENUE);

        $item = new CreateItemAction(
            data: new ItemData(
                app: $this->kanvasApp,
                company: $this->company,
                item_number: 'SVC-001',
                name: 'Hourly Consulting',
                type: ItemTypeEnum::SERVICE,
                default_income_account_id: $income,
                default_price_native: 150.0,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame('SVC-001', $item->item_number);
        $this->assertEquals(150.0, (float) $item->default_price_native);

        $updated = new UpdateItemAction(
            item: $item,
            data: new ItemData(
                app: $this->kanvasApp,
                company: $this->company,
                item_number: 'SVC-001',
                name: 'Senior Consulting',
                type: ItemTypeEnum::SERVICE,
                default_income_account_id: $income,
                default_price_native: 200.0,
                currency: 'USD',
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame('Senior Consulting', $updated->name);
        $this->assertEquals(200.0, (float) $updated->default_price_native);
    }

    public function test_create_tax_code_with_rates(): void
    {
        $itbis = $this->accountIdBySubType(AccountSubTypeEnum::SALES_TAX_PAYABLE);

        $taxCode = new CreateTaxCodeAction(
            data: new TaxCodeData(
                app: $this->kanvasApp,
                company: $this->company,
                code: 'ITBIS',
                name: 'DR ITBIS',
                jurisdiction: 'DO',
                rates: new DataCollection(TaxRateData::class, [
                    new TaxRateData(
                        name: 'Standard 18%',
                        rate: 18.0,
                        effective_from: Carbon::parse('2026-01-01'),
                        tax_account_id: $itbis,
                    ),
                ]),
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame('ITBIS', $taxCode->code);
        $this->assertCount(1, $taxCode->rates);
        $this->assertEquals(18.0, (float) $taxCode->rates->first()->rate);

        $updated = new UpdateTaxCodeAction(
            taxCode: $taxCode,
            data: new TaxCodeData(
                app: $this->kanvasApp,
                company: $this->company,
                code: 'ITBIS',
                name: 'DR ITBIS — updated',
                jurisdiction: 'DO',
                is_active: false,
            ),
            user: static::$cachedUser,
        )->execute();
        $this->assertSame('DR ITBIS — updated', $updated->name);
        $this->assertFalse((bool) $updated->is_active);
    }

    public function test_payment_term_default_flip_is_exclusive(): void
    {
        $net30 = new CreatePaymentTermAction(
            data: new PaymentTermData(
                app: $this->kanvasApp,
                company: $this->company,
                name: 'Net 30',
                net_days: 30,
                is_default: true,
            ),
            user: static::$cachedUser,
        )->execute();
        $this->assertTrue((bool) $net30->is_default);

        $net15 = new CreatePaymentTermAction(
            data: new PaymentTermData(
                app: $this->kanvasApp,
                company: $this->company,
                name: 'Net 15',
                net_days: 15,
                is_default: true,
            ),
            user: static::$cachedUser,
        )->execute();
        $this->assertTrue((bool) $net15->is_default);

        $net30->refresh();
        $this->assertFalse((bool) $net30->is_default, 'Previous default should be cleared when new default created.');

        $updated = new UpdatePaymentTermAction(
            paymentTerm: $net30,
            data: new PaymentTermData(
                app: $this->kanvasApp,
                company: $this->company,
                name: 'Net 30 — updated',
                net_days: 30,
                discount_days: 10,
                discount_pct: 2.0,
                is_default: true,
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertTrue((bool) $updated->is_default);
        $this->assertEquals(2.0, (float) $updated->discount_pct);
        $this->assertSame('Net 30 — updated', $updated->name);

        $net15->refresh();
        $this->assertFalse((bool) $net15->is_default);
    }

    private function createDraftInvoice(
        StubBillable $billable,
        float $unitPrice = 100.0,
        float $tax = 0.0,
    ): Invoice {
        return new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Service',
                        quantity: 1,
                        unit_price_native: $unitPrice,
                        tax_amount_native: $tax,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                net_terms_days: 30,
                issued_date: Carbon::parse('2026-06-15'),
            ),
            user: static::$cachedUser,
        )->execute();
    }

    private function issueTestInvoice(
        StubBillable $billable,
        float $subtotal = 1000.0,
        float $tax = 180.0,
    ): Invoice {
        $draft = $this->createDraftInvoice($billable, unitPrice: $subtotal, tax: $tax);

        return new IssueInvoiceAction(
            invoice: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();
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
