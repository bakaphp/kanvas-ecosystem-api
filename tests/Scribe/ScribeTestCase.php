<?php

declare(strict_types=1);

namespace Tests\Scribe;

use Baka\Contracts\BillableInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Actions\CreateExpenseAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\DataTransferObject\Expense as ExpenseData;
use Kanvas\Scribe\Expenses\DataTransferObject\ExpenseLine as ExpenseLineData;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Shared setup for every Scribe test class. Seeds the chart of accounts + an OPEN fiscal period
 * covering June 2026, and provides the helpers every test reaches for.
 *
 * Extend this instead of TestCase when the test touches Scribe data.
 */
abstract class ScribeTestCase extends TestCase
{
    use DatabaseTransactions;

    /**
     * `intelligence` is included so the NervousSystem ledger events emitted by Scribe Actions
     * (via EmitsLedgerEventsForEntity) get rolled back at the end of each test — otherwise
     * stale `nervous_system_events` rows leak across tests. `crm` is included because Scribe tests
     * seed Guild vendor/customer orgs (via seedTestOrganization) — without it those rows leak and
     * skew later tests (duplicate 'Globex Supply' orgs crowding out a find_vendor limit). `ecosystem`
     * is where approval_requests lives: Bill and Invoice use HasApprovals, so submitting either one
     * writes there even though it looks like a purely accounting-side action.
     */
    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm'];

    protected Apps $kanvasApp;
    protected Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        // Freeze "now" inside the open fiscal period. JE posting dates default to the transaction's
        // approval/issue timestamp (Carbon::now()), so tests must run as if the wall-clock sits inside
        // the seeded period — otherwise postings fall outside it once real time advances past the window.
        Carbon::setTestNow(Carbon::parse($this->fiscalPeriodStart() . ' 12:00:00'));

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        $this->ensureFiscalPeriod();
        $this->afterScribeSetUp();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * Override in child class when a test class needs to do additional setup (extra accounts,
     * extra fiscal periods, factory seeding). Default no-op.
     */
    protected function afterScribeSetUp(): void
    {
    }

    /**
     * Look up an account by its sub-type for the current tenant. Tests use this dozens of times to
     * pick the AR / AP / Cash / Travel & Meals / etc. account without hardcoding ids.
     */
    protected function accountIdBySubType(AccountSubTypeEnum $subType): int
    {
        $row = Account::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('account_sub_type', $subType->value)
            ->first();
        $this->assertNotNull($row, "Expected seeded account with sub_type='{$subType->value}'.");

        return (int) $row->id;
    }

    /**
     * Build a Filesystem row for tests that need an attached PDF / image. Defaults to the current
     * tenant; pass a different $appsId to exercise cross-tenant guards.
     */
    protected function createFilesystemRow(
        ?int $appsId = null,
        string $extension = 'pdf',
        string $fileType = 'pdf',
        ?string $url = null,
        ?string $name = null,
    ): Filesystem {
        $filesystem = new Filesystem();
        $filesystem->apps_id = $appsId ?? $this->kanvasApp->getId();
        $filesystem->companies_id = $this->company->getId();
        $filesystem->users_id = static::$cachedUser->getId();
        $filesystem->name = $name ?? 'test-' . uniqid('', true) . '.' . $extension;
        $filesystem->path = 'inbound/' . $filesystem->name;
        $filesystem->url = $url ?? 'https://example.test/' . $filesystem->path;
        $filesystem->size = '12345';
        $filesystem->file_type = $fileType;
        $filesystem->save();

        return $filesystem;
    }

    /**
     * Seed an OPEN fiscal period covering June 2026 unless one already exists. Overridable via
     * `protected ?array $fiscalPeriod = ['start' => ..., 'end' => ...]` for tests that want a
     * different window.
     */
    private function ensureFiscalPeriod(): void
    {
        $start = $this->fiscalPeriodStart();
        $end = $this->fiscalPeriodEnd();

        $exists = FiscalPeriod::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->exists();

        if ($exists) {
            return;
        }

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => $start,
            'period_end' => $end,
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    /**
     * Override to use a different fiscal period start. Format Y-m-d.
     */
    protected function fiscalPeriodStart(): string
    {
        return '2026-06-01';
    }

    protected function fiscalPeriodEnd(): string
    {
        return '2026-06-30';
    }

    // ───────────────────── shared E2E flow helpers ─────────────────────

    /**
     * Seed a real Guild Organization row for the current tenant. Tests should use this instead of
     * `new StubBillable()` so the polymorphic FK on Scribe transactions points at a real Guild
     * entity — same shape as production. Returns the Organization typed as BillableInterface +
     * PayeeInterface (it implements both via the Guild traits).
     */
    protected function seedTestOrganization(
        string $name = 'ACME Corp',
        ?string $address = '101 Main St, Santo Domingo, DO 10101',
    ): Organization {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => $address ?? '',
            'total_employees' => 0,
        ]);
    }

    /**
     * Issue a one-line invoice end-to-end (Create → Issue). Used by the cross-flow E2E tests
     * that need a "real" invoice on the books to exercise downstream reports / payments.
     */
    protected function issueTestInvoice(
        BillableInterface $billable,
        float $subtotal,
        float $tax = 0.0,
        string $issuedDate = '2026-06-15',
        string $dueDate = '2026-07-15',
    ): Invoice {
        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Test service',
                        quantity: 1,
                        unit_price_native: $subtotal,
                        tax_amount_native: $tax,
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

    /**
     * Submit + approve a one-line Expense end-to-end. The line lands on Travel & Meals (the
     * seeded sub-type) so reports pick it up under operating expenses.
     */
    protected function approveTestExpense(
        float $amount,
        ExpensePaidByEnum $paidBy = ExpensePaidByEnum::COMPANY_CARD,
        ?int $paidByUsersId = null,
    ): Expense {
        $travelAccountId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        $draft = new CreateExpenseAction(
            data: new ExpenseData(
                app: $this->kanvasApp,
                company: $this->company,
                lines: new DataCollection(ExpenseLineData::class, [
                    new ExpenseLineData(
                        description: 'Test expense line',
                        amount_native: $amount,
                        expense_account_id: $travelAccountId,
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
}
