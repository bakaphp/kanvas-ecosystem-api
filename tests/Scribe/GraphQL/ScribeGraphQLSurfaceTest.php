<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Bills\Actions\AttachBillReceiptAction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Tests\TestCase;

/**
 * Smoke coverage for the Scribe GraphQL surface introduced in PR 5.5.
 *
 * Verifies the high-level wiring: schema parses, queries return tenant-scoped lists, the most-used
 * mutations dispatch to the underlying Actions. This is not exhaustive — sub-ledger Action logic
 * is already covered by the unit tests in tests/Scribe/{Invoices,Quotes,SalesReceipts,Expenses,...}.
 */
class ScribeGraphQLSurfaceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        // JE posting dates default to Carbon::now(); freeze "now" inside the June 2026 fiscal period
        // so postings land in the open window regardless of the real wall-clock.
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

    public function test_scribe_accounts_list_returns_seeded_coa(): void
    {
        $this->graphQL('
            query {
                scribeAccounts(first: 5) {
                    data {
                        id
                        name
                        account_type
                        currency
                        is_system
                    }
                    paginatorInfo {
                        total
                    }
                }
            }
        ')->assertSuccessful()
            ->assertJsonPath('data.scribeAccounts.paginatorInfo.total', fn (int $total): bool => $total > 10);
    }

    public function test_scribe_fiscal_periods_list_returns_open_period(): void
    {
        $response = $this->graphQL('
            query {
                scribeFiscalPeriods(first: 10) {
                    data { id period_start period_end status }
                    paginatorInfo { total }
                }
            }
        ')->assertSuccessful();

        $payload = $response->json();
        $this->assertArrayNotHasKey(
            'errors',
            $payload,
            'GraphQL errors: ' . json_encode($payload['errors'] ?? null),
        );

        $statuses = collect($response->json('data.scribeFiscalPeriods.data'))->pluck('status')->all();
        $this->assertContains('OPEN', $statuses, 'Expected at least one period in OPEN state from setUp().');
    }

    public function test_create_scribe_bank_account_mutation_writes_row(): void
    {
        $cashAccountId = $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING);

        $response = $this->graphQL('
            mutation($input: ScribeBankAccountInput!) {
                createScribeBankAccount(input: $input) {
                    id
                    account_name
                    currency
                    is_active
                    gl_account { id }
                }
            }
        ', [
            'input' => [
                'account_name' => 'Mercury Primary',
                'gl_account_id' => $cashAccountId,
                'currency' => 'USD',
                'institution_name' => 'Mercury',
            ],
        ])->assertSuccessful();

        $response->assertJsonPath('data.createScribeBankAccount.account_name', 'Mercury Primary');
        $response->assertJsonPath('data.createScribeBankAccount.gl_account.id', (string) $cashAccountId);
    }

    public function test_create_scribe_expense_mutation_writes_draft_with_line(): void
    {
        $travelId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        $response = $this->graphQL('
            mutation($input: ScribeExpenseInput!) {
                createScribeExpense(input: $input) {
                    id
                    status
                    total_native
                    paid_by
                    lines {
                        id
                        amount_native
                        expense_account { id }
                    }
                }
            }
        ', [
            'input' => [
                'expense_date' => '2026-06-15',
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'paid_by' => 'COMPANY_CARD',
                'lines' => [[
                    'description' => 'AWS subscription',
                    'amount_native' => 89.0,
                    'expense_account_id' => $travelId,
                ]],
            ],
        ])->assertSuccessful();

        $response->assertJsonPath('data.createScribeExpense.status', 'DRAFT');
        $this->assertEquals(89.0, (float) $response->json('data.createScribeExpense.total_native'));
        $response->assertJsonPath('data.createScribeExpense.paid_by', 'COMPANY_CARD');
        $response->assertJsonPath('data.createScribeExpense.lines.0.expense_account.id', (string) $travelId);
    }

    public function test_full_expense_lifecycle_via_graphql(): void
    {
        $travelId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        $create = $this->graphQL('
            mutation($input: ScribeExpenseInput!) {
                createScribeExpense(input: $input) { id }
            }
        ', [
            'input' => [
                'expense_date' => '2026-06-15',
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'paid_by' => 'EMPLOYEE_PERSONAL',
                'paid_by_users_id' => static::$cachedUser->getId(),
                'lines' => [[
                    'description' => 'Hotel',
                    'amount_native' => 250.0,
                    'expense_account_id' => $travelId,
                ]],
            ],
        ])->assertSuccessful();

        $expenseId = $create->json('data.createScribeExpense.id');

        $this->graphQL('
            mutation($id: ID!) { submitScribeExpenseForApproval(id: $id) { id status } }
        ', ['id' => $expenseId])
            ->assertSuccessful()
            ->assertJsonPath('data.submitScribeExpenseForApproval.status', 'PENDING_APPROVAL');

        $this->graphQL('
            mutation($id: ID!) { approveScribeExpense(id: $id) { id status reimbursement_status } }
        ', ['id' => $expenseId])
            ->assertSuccessful()
            ->assertJsonPath('data.approveScribeExpense.status', 'APPROVED')
            ->assertJsonPath('data.approveScribeExpense.reimbursement_status', 'APPROVED');
    }

    public function test_attach_scribe_expense_receipt_mutation(): void
    {
        $travelId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        $create = $this->graphQL('
            mutation($input: ScribeExpenseInput!) {
                createScribeExpense(input: $input) { id }
            }
        ', [
            'input' => [
                'expense_date' => '2026-06-15',
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'paid_by' => 'COMPANY_CARD',
                'lines' => [[
                    'description' => 'Coffee',
                    'amount_native' => 12.0,
                    'expense_account_id' => $travelId,
                ]],
            ],
        ])->assertSuccessful();

        $expenseId = $create->json('data.createScribeExpense.id');

        $filesystem = new Filesystem();
        $filesystem->apps_id = $this->kanvasApp->getId();
        $filesystem->companies_id = $this->company->getId();
        $filesystem->users_id = static::$cachedUser->getId();
        $filesystem->name = 'coffee.pdf';
        $filesystem->path = 'expenses/coffee.pdf';
        $filesystem->url = 'https://example.test/expenses/coffee.pdf';
        $filesystem->size = '4096';
        $filesystem->file_type = 'pdf';
        $filesystem->save();

        $response = $this->graphQL('
            mutation($id: ID!, $input: ScribeAttachExpenseReceiptInput!) {
                attachScribeExpenseReceipt(id: $id, input: $input) {
                    id
                    filesystem { id }
                }
            }
        ', [
            'id' => $expenseId,
            'input' => ['filesystem_id' => (int) $filesystem->id],
        ])->assertSuccessful();

        $response->assertJsonPath('data.attachScribeExpenseReceipt.filesystem.id', (string) $filesystem->id);
    }

    public function test_scribe_bill_exposes_its_receipts_via_graphql(): void
    {
        $travelId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);

        $create = $this->graphQL('
            mutation($input: ScribeBillInput!) {
                createScribeBill(input: $input) { id }
            }
        ', [
            'input' => [
                'bill_number' => 'RECEIPT-GQL-1',
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'lines' => [[
                    'description' => 'Consulting',
                    'unit_price_native' => 250.0,
                    'expense_account_id' => $travelId,
                ]],
            ],
        ])->assertSuccessful();

        $billId = $create->json('data.createScribeBill.id');

        $filesystem = new Filesystem();
        $filesystem->apps_id = $this->kanvasApp->getId();
        $filesystem->companies_id = $this->company->getId();
        $filesystem->users_id = static::$cachedUser->getId();
        $filesystem->name = 'invoice.pdf';
        $filesystem->path = 'bills/invoice.pdf';
        $filesystem->url = 'https://example.test/bills/invoice.pdf';
        $filesystem->size = '4096';
        $filesystem->file_type = 'pdf';
        $filesystem->save();

        $bill = Bill::query()->where('id', $billId)->first();
        new AttachBillReceiptAction($bill, $filesystem, static::$cachedUser)->execute();

        $response = $this->graphQL('
            query($billNumber: Mixed) {
                scribeBills(where: {column: BILL_NUMBER, operator: EQ, value: $billNumber}) {
                    data {
                        id
                        receipts { filesystem { id url } }
                    }
                }
            }
        ', ['billNumber' => 'RECEIPT-GQL-1'])->assertSuccessful();

        $response->assertJsonPath('data.scribeBills.data.0.id', (string) $billId);
        $response->assertJsonPath('data.scribeBills.data.0.receipts.0.filesystem.id', (string) $filesystem->id);
        $response->assertJsonPath('data.scribeBills.data.0.receipts.0.filesystem.url', 'https://example.test/bills/invoice.pdf');
    }

    public function test_scribe_expenses_list_filters_by_status(): void
    {
        $this->graphQL('
            mutation($input: ScribeExpenseInput!) { createScribeExpense(input: $input) { id } }
        ', [
            'input' => [
                'expense_date' => '2026-06-15',
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'paid_by' => 'COMPANY_CARD',
                'lines' => [[
                    'description' => 'Test',
                    'amount_native' => 10.0,
                    'expense_account_id' => $this->accountIdBySubType(AccountSubTypeEnum::OFFICE_SUPPLIES),
                ]],
            ],
        ])->assertSuccessful();

        $this->graphQL('
            query {
                scribeExpenses(first: 50) {
                    data { id status }
                    paginatorInfo { total }
                }
            }
        ')->assertSuccessful()
            ->assertJsonPath('data.scribeExpenses.paginatorInfo.total', fn (int $t): bool => $t >= 1);
    }

    public function test_scribe_journal_entries_list_works(): void
    {
        $this->graphQL('
            query {
                scribeJournalEntries(first: 10) {
                    data { id status posted_at source_type }
                    paginatorInfo { total }
                }
            }
        ')->assertSuccessful();
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
}
