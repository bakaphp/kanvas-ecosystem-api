<?php

declare(strict_types=1);

namespace Tests\Scribe\GraphQL;

use Baka\Traits\HasLightHouseCache;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Banking\Enums\BankTransactionDirectionEnum;
use Kanvas\Scribe\Banking\Models\BankAccount;
use Kanvas\Scribe\Banking\Models\BankTransaction;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Items\Models\Item;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Quotes\Models\Quote;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Every primary accounting document must actually RESOLVE `files` over the graph — not merely
     * declare it. A schema-only assertion passes even when the paginator builder, the system-module
     * lookup or the model's own files() relation blows up at query time.
     */
    #[DataProvider('documentTypesExposingFiles')]
    public function test_scribe_document_resolves_its_files_over_graphql(
        string $modelClass,
        string $graphTypeName,
        string $schemaFile,
        string $queryName,
        string $whereColumn,
        string $fileField
    ): void {
        $reference = 'FILES-GQL-' . strtoupper(substr(md5($graphTypeName), 0, 8));
        $document = $this->makeDocument($graphTypeName, $reference);

        $fileUrl = 'https://example.test/docs/' . uniqid('', true) . '.pdf';
        $document->addFileFromUrl($fileUrl, $fileField);

        $filterValue = $document->{strtolower($whereColumn)};
        $filterValue = $filterValue instanceof Carbon ? $filterValue->toDateString() : (string) $filterValue;

        $response = $this->queryDocumentFiles($queryName, $whereColumn, $filterValue);

        $response->assertJsonPath("data.{$queryName}.data.0.id", (string) $document->getId());
        $response->assertJsonPath("data.{$queryName}.data.0.files.data.0.url", $fileUrl);
    }

    /**
     * The reason the HasLightHouseCache + observer wiring exists at all. @cacheRedis writes to a real
     * Redis hash (it bypasses the cache facade, so CACHE_DRIVER=array does not neuter it) — without
     * invalidation a second read serves the pre-upload list and a file the agent just attached is
     * invisible until the key expires. Reads first so the cache is warm before the second attach.
     */
    public function test_a_file_attached_after_a_cached_read_still_appears(): void
    {
        $reference = 'FILES-GQL-CACHE-1';
        $quote = $this->makeDocument('ScribeQuote', $reference);

        $firstUrl = 'https://example.test/docs/' . uniqid('', true) . '.pdf';
        $quote->addFileFromUrl($firstUrl, 'quote_pdf');

        $warm = $this->queryDocumentFiles('scribeQuotes', 'QUOTE_NUMBER', $reference);
        $warm->assertJsonPath('data.scribeQuotes.data.0.files.data.0.url', $firstUrl);

        $secondUrl = 'https://example.test/docs/' . uniqid('', true) . '.pdf';
        $quote->addFileFromUrl($secondUrl, 'signed_acceptance');

        $urls = $this->queryDocumentFiles('scribeQuotes', 'QUOTE_NUMBER', $reference)
            ->json('data.scribeQuotes.data.0.files.data.*.url');

        $this->assertContains($firstUrl, $urls);
        $this->assertContains(
            $secondUrl,
            $urls,
            'A file attached after a cached read must invalidate the @cacheRedis entry, or it stays invisible.',
        );
    }

    private function queryDocumentFiles(string $queryName, string $whereColumn, string $reference): TestResponse
    {
        return $this->graphQL('
            query($reference: Mixed) {
                ' . $queryName . '(where: {column: ' . $whereColumn . ', operator: EQ, value: $reference}) {
                    data {
                        id
                        files { data { url } }
                    }
                }
            }
        ', ['reference' => $reference])->assertSuccessful();
    }

    /**
     * Bill/Invoice/Quote go through their create mutation so this keeps covering that path; the rest
     * are built directly because their `files` surface is what is under test, not their creation.
     */
    private function makeDocument(string $graphTypeName, string $reference): Model
    {
        return match ($graphTypeName) {
            'ScribeBill' => $this->createViaMutation(
                'createScribeBill',
                'ScribeBillInput',
                Bill::class,
                [
                    'bill_number' => $reference,
                    'currency' => 'USD',
                    'fx_rate_to_base' => 1.0,
                    'lines' => [[
                        'description' => 'Consulting',
                        'unit_price_native' => 250.0,
                        'expense_account_id' => $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS),
                    ]],
                ],
            ),
            'ScribeInvoice' => $this->createViaMutation(
                'createScribeInvoice',
                'ScribeInvoiceInput',
                Invoice::class,
                [
                    'invoice_number' => $reference,
                    'currency' => 'USD',
                    'fx_rate_to_base' => 1.0,
                    'lines' => [['description' => 'Consulting', 'unit_price_native' => 250.0]],
                ],
            ),
            'ScribeQuote' => $this->createViaMutation(
                'createScribeQuote',
                'ScribeQuoteInput',
                Quote::class,
                [
                    'quote_number' => $reference,
                    'currency' => 'USD',
                    'fx_rate_to_base' => 1.0,
                    'lines' => [['description' => 'Consulting', 'unit_price_native' => 250.0]],
                ],
            ),
            'ScribeExpense' => $this->newDocument(Expense::class, [
                'expense_number' => $reference,
                'expense_date' => '2026-06-15',
                'paid_by' => ExpensePaidByEnum::COMPANY_CARD->value,
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
            ]),
            'ScribeSalesReceipt' => $this->newDocument(SalesReceipt::class, [
                'receipt_number' => $reference,
                'receipt_date' => '2026-06-15',
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
            ]),
            'ScribePurchaseOrder' => $this->newDocument(PurchaseOrder::class, [
                'order_number' => $reference,
                'order_type' => 'PO',
                'order_total' => 500.0,
                'currency' => 'USD',
                'order_date' => '2026-06-15',
                'source' => 'test',
            ]),
            'ScribeBankTransaction' => $this->newDocument(BankTransaction::class, [
                'external_id' => $reference,
                'bank_account_id' => $this->testBankAccount()->getId(),
                'posted_at' => '2026-06-15 10:00:00',
                'transaction_date' => '2026-06-15',
                'direction' => BankTransactionDirectionEnum::CREDIT->value,
                'amount_native' => 100.0,
                'amount_base' => 100.0,
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'source' => 'test',
            ]),
            'ScribeBankAccount' => $this->testBankAccount($reference),
            'ScribeItem' => $this->newDocument(Item::class, [
                'item_number' => $reference,
                'name' => $reference,
            ]),
            'ScribeSubaccount' => $this->newDocument(Subaccount::class, [
                'sub_code' => $reference,
                'description' => 'Files test subaccount',
            ]),
            // Its own range, well clear of the seeded June 2026 period the other cases post into.
            'ScribeFiscalPeriod' => $this->newDocument(FiscalPeriod::class, [
                'period_start' => '2027-01-01',
                'period_end' => '2027-01-31',
                'status' => FiscalPeriodStatusEnum::HARD_CLOSED,
            ]),
            'ScribePayment' => $this->newDocument(Payment::class, [
                'external_id' => $reference,
                'amount_native' => 100.0,
                'amount_base' => 100.0,
                'currency' => 'USD',
                'fx_rate_to_base' => 1.0,
                'payment_date' => '2026-06-15',
                'direction' => PaymentDirectionEnum::INBOUND->value,
                'method' => PaymentMethodEnum::CHECK->value,
            ]),
        };
    }

    /**
     * @param array<string, mixed> $input
     * @param class-string<Model> $modelClass
     */
    private function createViaMutation(
        string $mutation,
        string $inputType,
        string $modelClass,
        array $input
    ): Model {
        $id = $this->graphQL('
            mutation($input: ' . $inputType . '!) {
                ' . $mutation . '(input: $input) { id }
            }
        ', ['input' => $input])
            ->assertSuccessful()
            ->json('data.' . $mutation . '.id');

        return $modelClass::query()->where('id', $id)->firstOrFail();
    }

    private function testBankAccount(?string $accountName = null): BankAccount
    {
        return $this->newDocument(BankAccount::class, [
            'account_name' => $accountName ?? 'Files Test Operating Account',
            'currency' => 'USD',
            'gl_account_id' => $this->accountIdBySubType(AccountSubTypeEnum::CASH_CHECKING),
        ]);
    }

    /**
     * @param class-string<Model> $modelClass
     * @param array<string, mixed> $attributes
     */
    private function newDocument(string $modelClass, array $attributes): Model
    {
        $document = new $modelClass();
        $document->fill($attributes);
        $document->apps_id = $this->kanvasApp->getId();
        $document->companies_id = $this->company->getId();

        // fiscal_periods tracks its closer, not an owner — it has no users_id column.
        if (Schema::connection($document->getConnectionName())->hasColumn($document->getTable(), 'users_id')) {
            $document->users_id = static::$cachedUser->getId();
        }

        $document->saveOrFail();

        return $document;
    }

    /**
     * `files` on a type is only half the wiring: without HasLightHouseCache + a getGraphTypeName()
     * that matches the type, @cacheRedis keeps serving the pre-upload payload and a file the agent
     * just attached never appears. Guards the pairing in both directions.
     */
    #[DataProvider('documentTypesExposingFiles')]
    public function test_every_scribe_document_exposing_files_is_wired_for_cache_invalidation(
        string $modelClass,
        string $graphTypeName,
        string $schemaFile,
        string $queryName,
        string $whereColumn,
        string $fileField
    ): void {
        $this->assertContains(
            HasLightHouseCache::class,
            class_uses_recursive($modelClass),
            $modelClass . ' exposes files but cannot invalidate its Lighthouse cache.',
        );

        $this->assertSame($graphTypeName, new $modelClass()->getGraphTypeName());

        $schema = file_get_contents(base_path('graphql/schemas/Scribe/' . $schemaFile));
        $type = substr($schema, strpos($schema, 'type ' . $graphTypeName . ' {'));
        $type = substr($type, 0, strpos($type, "\n}\n"));

        $this->assertStringContainsString('files: [Filesystem!]!', $type);
        $this->assertStringContainsString('@cacheRedis', $type);

        // A type whose files nothing can reach is the same as no files at all.
        $this->assertStringContainsString($queryName . '(', $schema);
        $this->assertStringContainsString('"' . strtolower($whereColumn) . '"', $schema);
        $this->assertNotSame('', $fileField);
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    public static function documentTypesExposingFiles(): array
    {
        return [
            'bill' => [Bill::class, 'ScribeBill', 'bill.graphql', 'scribeBills', 'BILL_NUMBER', 'invoice_pdf'],
            'invoice' => [Invoice::class, 'ScribeInvoice', 'invoice.graphql', 'scribeInvoices', 'INVOICE_NUMBER', 'invoice_pdf'],
            'quote' => [Quote::class, 'ScribeQuote', 'quote.graphql', 'scribeQuotes', 'QUOTE_NUMBER', 'quote_pdf'],
            'expense' => [Expense::class, 'ScribeExpense', 'expense.graphql', 'scribeExpenses', 'EXPENSE_NUMBER', 'receipt'],
            'sales receipt' => [SalesReceipt::class, 'ScribeSalesReceipt', 'salesReceipt.graphql', 'scribeSalesReceipts', 'RECEIPT_NUMBER', 'receipt_pdf'],
            'payment' => [Payment::class, 'ScribePayment', 'payment.graphql', 'scribePayments', 'EXTERNAL_ID', 'remittance'],
            'purchase order' => [PurchaseOrder::class, 'ScribePurchaseOrder', 'purchaseOrder.graphql', 'scribePurchaseOrders', 'ORDER_NUMBER', 'po_pdf'],
            'bank transaction' => [BankTransaction::class, 'ScribeBankTransaction', 'banking.graphql', 'scribeBankTransactions', 'EXTERNAL_ID', 'statement'],
            'bank account' => [BankAccount::class, 'ScribeBankAccount', 'banking.graphql', 'scribeBankAccounts', 'ACCOUNT_NAME', 'bank_letter'],
            'item' => [Item::class, 'ScribeItem', 'masterData.graphql', 'scribeItems', 'NAME', 'spec_sheet'],
            'subaccount' => [Subaccount::class, 'ScribeSubaccount', 'ledger.graphql', 'scribeSubaccounts', 'SUB_CODE', 'supporting_doc'],
            'fiscal period' => [FiscalPeriod::class, 'ScribeFiscalPeriod', 'ledger.graphql', 'scribeFiscalPeriods', 'PERIOD_START', 'close_packet'],
        ];
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
