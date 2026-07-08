<?php

declare(strict_types=1);

namespace Tests\Scribe\Reports;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\AgingEvaluationService;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\Reports\Enums\RevenueGroupByEnum;
use Kanvas\Scribe\Reports\Repositories\ArAgingRepository;
use Kanvas\Scribe\Reports\Repositories\BalanceSheetRepository;
use Kanvas\Scribe\Reports\Repositories\ProfitAndLossRepository;
use Kanvas\Scribe\Reports\Repositories\RevenueRepository;
use Kanvas\Scribe\Reports\Repositories\TrialBalanceRepository;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Coverage for PR 8a Reports + Aging:
 *   - BalanceSheetRepository — balances after invoice issue, after payment, after expense
 *   - ProfitAndLossRepository — revenue + COGS + expenses + net income
 *   - TrialBalanceRepository — DR == CR grand-total invariant
 *   - ArAgingRepository — bucketed open AR by customer
 *   - RevenueRepository — customer / month / item groupings
 *   - AgingEvaluationService — collection_state transitions, disputed/uncollectible preserved
 *   - End-to-end §10 Flow 1 (invoice → payment) and §10 Flow 2 (Juan's hotel expense)
 */
class ReportsTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        // JE posting dates default to Carbon::now(); freeze "now" inside the June 2026 fiscal period
        // so postings land in the open window (and inside the June reporting period) regardless of the
        // real wall-clock.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00'));

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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_balance_sheet_balances_after_invoice_issue(): void
    {
        $billable = $this->seedOrganization();
        $this->issueTestInvoice($billable, subtotal: 1000.0, tax: 0.0);

        $bs = new BalanceSheetRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );

        $this->assertTrue($bs->is_balanced, 'BS must balance: A = L + E.');
        $this->assertEqualsWithDelta(
            $bs->assets->total,
            $bs->total_liabilities_and_equity,
            0.005,
        );

        $arLine = $bs->assets->lines->toCollection()->first(
            fn ($l) => $l->account_sub_type === AccountSubTypeEnum::ACCOUNTS_RECEIVABLE->value,
        );
        $this->assertNotNull($arLine, 'AR line should appear in Assets.');
        $this->assertEquals(1000.0, $arLine->amount);

        $cyeLine = $bs->equity->lines->toCollection()->first(fn ($l) => $l->account_number === '__cye__');
        $this->assertNotNull($cyeLine);
        $this->assertEquals(1000.0, $cyeLine->amount, 'Current Year Earnings = revenue through asOf.');
    }

    public function test_profit_and_loss_for_period_with_revenue_and_expense(): void
    {
        $billable = $this->seedOrganization();
        $this->issueTestInvoice($billable, subtotal: 2000.0, tax: 0.0);
        $this->approveExpense(amount: 500.0);

        $pnl = new ProfitAndLossRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
        );

        $this->assertEquals(2000.0, $pnl->revenue->total);
        $this->assertEquals(0.0, $pnl->cogs->total);
        $this->assertEquals(2000.0, $pnl->gross_profit);
        $this->assertEquals(500.0, $pnl->operating_expenses->total);
        $this->assertEquals(1500.0, $pnl->operating_income);
        $this->assertEquals(1500.0, $pnl->net_income);
    }

    public function test_trial_balance_grand_total_balances(): void
    {
        $billable = $this->seedOrganization();
        $this->issueTestInvoice($billable, subtotal: 750.0, tax: 50.0);
        $this->approveExpense(amount: 200.0);

        $tb = new TrialBalanceRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );

        $this->assertTrue($tb->is_balanced, 'Trial balance grand-total DR must equal CR.');
        $this->assertEqualsWithDelta($tb->total_debits, $tb->total_credits, 0.005);
        $this->assertGreaterThan(0, $tb->rows->count());

        $ar = $tb->rows->toCollection()->first(fn ($r) => $r->account_sub_type === AccountSubTypeEnum::ACCOUNTS_RECEIVABLE->value);
        $this->assertNotNull($ar);
        $this->assertEquals(800.0, $ar->debit, 'AR is DR-normal — balance shows in debit column.');
        $this->assertEquals(0.0, $ar->credit);
    }

    public function test_ar_aging_buckets_by_due_date(): void
    {
        $billable = $this->seedOrganization();
        // Three invoices with staggered due dates so they fall into different buckets relative to 2026-09-01
        $this->issueAtDueDate($billable, 1000.0, dueDate: '2026-09-15');         // future → CURRENT
        $this->issueAtDueDate($billable, 500.0, dueDate: '2026-08-15');           // ~17 days → 1-30
        $this->issueAtDueDate($billable, 300.0, dueDate: '2026-07-15');           // ~48 days → 31-60

        $report = new ArAgingRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-09-01'),
        );

        $this->assertEquals(1000.0, $report->total_current);
        $this->assertEquals(500.0, $report->total_1_30);
        $this->assertEquals(300.0, $report->total_31_60);
        $this->assertEquals(0.0, $report->total_61_90);
        $this->assertEquals(0.0, $report->total_90_plus);
        $this->assertEquals(1800.0, $report->grand_total);

        $this->assertCount(1, $report->rows, 'Single customer rolls up to one row.');
        $row = $report->rows->toCollection()->first();
        $this->assertEquals(1800.0, $row->total);
    }

    public function test_revenue_report_groups_by_customer(): void
    {
        $billableA = $this->seedOrganization('ACME Corp');
        $billableB = $this->seedOrganization('Globex');

        $this->issueTestInvoice($billableA, subtotal: 1000.0, tax: 0.0);
        $this->issueTestInvoice($billableA, subtotal: 500.0, tax: 0.0);
        $this->issueTestInvoice($billableB, subtotal: 2000.0, tax: 0.0);

        $report = new RevenueRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
            groupBy: RevenueGroupByEnum::CUSTOMER,
        );

        $this->assertSame(3500.0, $report->total_net_revenue);
        $this->assertSame(3, $report->total_invoice_count);
        $this->assertCount(2, $report->rows);

        $top = $report->rows->toCollection()->first();
        $this->assertSame(2000.0, $top->net_revenue, 'Highest revenue customer ranked first.');
    }

    public function test_revenue_report_groups_by_month(): void
    {
        $billable = $this->seedOrganization();
        $this->issueAtDueDate($billable, 1500.0, dueDate: '2026-07-30', issuedDate: '2026-06-15');
        $this->issueAtDueDate($billable, 800.0, dueDate: '2026-08-30', issuedDate: '2026-06-25');

        $report = new RevenueRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
            groupBy: RevenueGroupByEnum::MONTH,
        );

        $this->assertCount(1, $report->rows);
        $firstRow = $report->rows->toCollection()->first();
        $this->assertSame('2026-06', $firstRow->group_key);
        $this->assertSame(2300.0, $firstRow->net_revenue);
    }

    public function test_aging_evaluation_service_flips_overdue_invoices(): void
    {
        $billable = $this->seedOrganization();
        $invoice = $this->issueAtDueDate($billable, 1000.0, dueDate: '2026-06-15');
        $this->assertSame(InvoiceCollectionStateEnum::CURRENT, $invoice->collection_state);

        $result = new AgingEvaluationService()->evaluate(
            app: $this->kanvasApp,
            company: $this->company,
            today: Carbon::parse('2026-07-15'),
        );

        $this->assertSame(1, $result['evaluated']);
        $this->assertSame(1, $result['transitioned']);

        $invoice->refresh();
        $this->assertSame(InvoiceCollectionStateEnum::OVERDUE, $invoice->collection_state);
    }

    public function test_aging_evaluation_preserves_disputed_state(): void
    {
        $billable = $this->seedOrganization();
        $invoice = $this->issueAtDueDate($billable, 1000.0, dueDate: '2026-06-15');
        $invoice->collection_state = InvoiceCollectionStateEnum::DISPUTED;
        $invoice->save();

        new AgingEvaluationService()->evaluate(
            app: $this->kanvasApp,
            company: $this->company,
            today: Carbon::parse('2026-08-15'),
        );

        $invoice->refresh();
        $this->assertSame(
            InvoiceCollectionStateEnum::DISPUTED,
            $invoice->collection_state,
            'Disputed invoices must be left alone by aging evaluation.',
        );
    }

    public function test_e2e_flow_1_invoice_then_payment_through_reports(): void
    {
        $billable = $this->seedOrganization();
        $invoice = $this->issueTestInvoice($billable, subtotal: 1000.0, tax: 0.0);

        $bsBeforePayment = new BalanceSheetRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-20'),
        );
        $arBefore = $bsBeforePayment->assets->lines->toCollection()->first(
            fn ($l) => $l->account_sub_type === AccountSubTypeEnum::ACCOUNTS_RECEIVABLE->value,
        );
        $this->assertEquals(1000.0, $arBefore?->amount);

        $invoice->document_status = InvoiceDocumentStatusEnum::PAID;
        $invoice->paid_native = 1000.0;
        $invoice->paid_base = 1000.0;
        $invoice->balance_due_native = 0.0;
        $invoice->balance_due_base = 0.0;
        $invoice->save();

        $aging = new ArAgingRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );
        $this->assertEquals(0.0, $aging->grand_total, 'Paid invoice should drop out of AR aging.');
    }

    public function test_e2e_flow_2_juans_hotel_through_pnl_and_balance_sheet(): void
    {
        $expense = $this->approveExpense(
            amount: 500.0,
            paidBy: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            paidByUsersId: static::$cachedUser->getId(),
        );

        $pnl = new ProfitAndLossRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
        );
        $this->assertEquals(500.0, $pnl->operating_expenses->total);
        $this->assertEquals(-500.0, $pnl->net_income, 'Expense without revenue → negative net income.');

        $bs = new BalanceSheetRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );
        $this->assertTrue($bs->is_balanced);

        $dueToEmp = $bs->liabilities->lines->toCollection()->first(
            fn ($l) => $l->account_sub_type === AccountSubTypeEnum::DUE_TO_EMPLOYEES->value,
        );
        $this->assertNotNull($dueToEmp);
        $this->assertEquals(500.0, $dueToEmp->amount);

        new RecordExpenseReimbursementAction(
            expense: $expense,
            user: static::$cachedUser,
        )->execute();

        $bsAfter = new BalanceSheetRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );
        $dueToEmpAfter = $bsAfter->liabilities->lines->toCollection()->first(
            fn ($l) => $l->account_sub_type === AccountSubTypeEnum::DUE_TO_EMPLOYEES->value,
        );
        $this->assertNull(
            $dueToEmpAfter,
            'Due to Employees should net to zero after reimbursement (line drops out).',
        );
        $this->assertTrue($bsAfter->is_balanced);
    }

    private function issueTestInvoice(
        Organization $billable,
        float $subtotal,
        float $tax,
    ): Invoice {
        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Service',
                        quantity: 1,
                        unit_price_native: $subtotal,
                        tax_amount_native: $tax,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                net_terms_days: 30,
                issued_date: Carbon::parse('2026-06-15'),
                due_date: Carbon::parse('2026-07-15'),
            ),
            user: static::$cachedUser,
        )->execute();

        return new IssueInvoiceAction(
            invoice: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();
    }

    private function issueAtDueDate(
        Organization $billable,
        float $amount,
        string $dueDate,
        string $issuedDate = '2026-06-15',
    ): Invoice {
        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Service',
                        quantity: 1,
                        unit_price_native: $amount,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse($issuedDate),
                due_date: Carbon::parse($dueDate),
            ),
            user: static::$cachedUser,
        )->execute();

        return new IssueInvoiceAction(
            invoice: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();
    }

    private function approveExpense(
        float $amount,
        ExpensePaidByEnum $paidBy = ExpensePaidByEnum::COMPANY_CARD,
        ?int $paidByUsersId = null,
    ): \Kanvas\Scribe\Expenses\Models\Expense {
        $travelId = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', AccountSubTypeEnum::TRAVEL_AND_MEALS->value)
            ->value('id');

        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Hotel',
                        amount_native: $amount,
                        expense_account_id: (int) $travelId,
                    ),
                ]),
                expense_date: Carbon::parse('2026-06-15'),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                paid_by: $paidBy,
                paid_by_users_id: $paidByUsersId,
            ),
            user: static::$cachedUser,
        )->execute();

        $submitted = new SubmitExpenseForApprovalAction(
            expense: $draft,
            user: static::$cachedUser,
        )->execute();

        return new ApproveExpenseAction(
            expense: $submitted,
            approver: static::$cachedUser,
        )->execute();
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
