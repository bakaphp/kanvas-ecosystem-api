<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Illuminate\Support\Str;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\NervousSystem\Scheduling\Models\ScheduledAction;
use Kanvas\Scribe\Bills\Contracts\ExpenseAccountSuggesterInterface;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\Items\Models\Item;
use Kanvas\Scribe\Ledger\Enums\AccountTypeEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\Actions\ProposeBillFromPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Scribe\Setup\Enums\AccountingSetupConfigurationEnum;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;
use Tests\Scribe\Setup\Concerns\UsesFreshAccountingCompany;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * Plan 4.3: an inbox PDF from a supplier ends as a finished bill on a Dominican company — vendor found by
 * RNC (or created and checked at DGII), NCF checked, coded from item or AI — or as a draft whose task tells
 * the AP agent exactly what to fix.
 */
final class InboxBillFinishingTest extends ScribeTestCase
{
    use UsesFreshAccountingCompany;

    private const string RNC = '131794321';

    private Companies $dr;

    protected function afterScribeSetUp(): void
    {
        FakeEcfProvider::reset();
        config()->set('scribe.ecf_providers.fake', FakeEcfProvider::class);

        $this->dr = $this->freshCompany('DO');
        new ChartOfAccountsSeederService()->seedForCountry(
            appsId: $this->kanvasApp->getId(),
            companiesId: $this->dr->getId(),
            countryCode: 'DO',
            userId: static::$cachedUser->getId(),
        );
        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->dr->getId(),
            'period_start' => $this->fiscalPeriodStart(),
            'period_end' => $this->fiscalPeriodEnd(),
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
        $this->dr->set(EcfConfigurationEnum::ECF_PROVIDER->value, 'fake');
        FakeEcfProvider::$taxpayers = [self::RNC => 'SUMINISTROS DEL CARIBE SRL'];
    }

    protected function tearDown(): void
    {
        FakeEcfProvider::reset();

        parent::tearDown();
    }

    public function test_a_valid_ncf_from_a_registered_supplier_coded_by_item_goes_to_approval(): void
    {
        $account = $this->expenseAccount();
        $this->item('Toner HP 85A', $account);

        [$log, $bill] = $this->ingest(['ncf' => 'B0100000123', 'line_items' => [['description' => 'Toner HP 85A', 'qty' => 2, 'unit_price' => 50, 'line_total' => 100]]]);

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $bill->document_status);
        $this->assertSame('item', $bill->metadata['ingest']['gl_source']);
        $this->assertSame((int) $account->getId(), (int) $bill->lines()->first()->expense_account_id);

        $ncf = $bill->regional_compliance['do_ncf'];
        $this->assertTrue($ncf['valid']);
        $this->assertSame('01', $ncf['type']);
        $this->assertSame(self::RNC, $ncf['vendor_rnc']);
        $this->assertSame('found', $ncf['rnc_status']);
        $this->assertSame('SUMINISTROS DEL CARIBE SRL', $ncf['rnc_legal_name']);
        $this->assertSame(self::RNC, Organization::query()->findOrFail($bill->vendor_organization_id)->getPayeeTaxId());
    }

    public function test_a_missing_ncf_holds_the_bill_and_the_ap_agent_asks_the_supplier_for_a_corrected_invoice(): void
    {
        $ap = $this->accountingAgent($this->dr, AccountsPayableAgent::class);
        $this->item('Toner HP 85A', $this->expenseAccount());

        [$log, $bill] = $this->ingest(['line_items' => [['description' => 'Toner HP 85A', 'line_total' => 100]]]);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertSame('ncf_invalid', $bill->metadata['ingest']['review_code']);
        $this->assertSame('missing', $bill->regional_compliance['do_ncf']['code']);

        $task = ScheduledAction::query()->where('agent_id', $ap->getId())->latest('id')->firstOrFail();
        $this->assertStringContainsString('corrected invoice', (string) $task->payload['instruction']);
    }

    public function test_a_consumer_ncf_is_the_wrong_type_for_a_bill(): void
    {
        $this->item('Toner HP 85A', $this->expenseAccount());

        [, $bill] = $this->ingest(['ncf' => 'B0200000123', 'line_items' => [['description' => 'Toner HP 85A', 'line_total' => 100]]]);

        $this->assertSame('wrong_type', $bill->regional_compliance['do_ncf']['code']);
        $this->assertStringContainsString('B01', (string) $bill->metadata['ingest']['review_reason']);
    }

    public function test_an_rnc_dgii_does_not_know_holds_the_bill(): void
    {
        FakeEcfProvider::$taxpayers = [];
        $this->item('Toner HP 85A', $this->expenseAccount());

        [$log, $bill] = $this->ingest(['ncf' => 'B0100000123', 'line_items' => [['description' => 'Toner HP 85A', 'line_total' => 100]]]);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertSame('rnc_not_registered', $bill->metadata['ingest']['review_code']);
        $this->assertSame('not_registered', $bill->regional_compliance['do_ncf']['rnc_status']);
    }

    public function test_the_vendor_is_found_by_rnc_even_under_another_name(): void
    {
        $vendor = Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->dr->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => 'Suministros (legal) ' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
        $vendor->tax_id = self::RNC;
        $vendor->saveOrFail();

        [, $bill] = $this->ingest(['ncf' => 'B0100000123', 'vendor_name' => 'SDC Trading']);

        $this->assertSame($vendor->getId(), (int) $bill->vendor_organization_id);
    }

    public function test_a_name_match_carrying_another_rnc_is_not_used_as_the_vendor(): void
    {
        $name = 'Suministros Homonimos ' . uniqid();
        $other = Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->dr->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
        $other->tax_id = '101000001';
        $other->saveOrFail();

        [$log, $bill] = $this->ingest(['ncf' => 'B0100000123', 'vendor_name' => $name]);

        $this->assertNull($bill->vendor_organization_id);
        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertSame(ProposeBillFromPdfAction::REVIEW_VENDOR, $bill->metadata['ingest']['review_code']);
    }

    public function test_an_ai_coding_goes_to_approval_with_its_confidence_on_the_bill(): void
    {
        $account = $this->expenseAccount();
        $this->dr->set(AccountingSetupConfigurationEnum::AI_EXPENSE_CODING->value, true);
        $this->suggest((string) $account->account_number, 0.72);

        [$log, $bill] = $this->ingest(['ncf' => 'B0100000123', 'line_items' => [['description' => 'Cloud hosting November', 'line_total' => 100]]]);

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame('ai', $bill->metadata['ingest']['gl_source']);
        $this->assertSame(0.72, $bill->metadata['ingest']['gl_coding']['confidence']);
        $this->assertStringContainsString('confidence 72%', (string) $bill->internal_notes);
        $this->assertSame((int) $account->getId(), (int) $bill->lines()->first()->expense_account_id);
    }

    public function test_an_unsure_ai_coding_leaves_the_bill_for_review(): void
    {
        $this->dr->set(AccountingSetupConfigurationEnum::AI_EXPENSE_CODING->value, true);
        $this->suggest((string) $this->expenseAccount()->account_number, 0.1);

        [$log, $bill] = $this->ingest(['ncf' => 'B0100000123', 'line_items' => [['description' => 'Misc', 'line_total' => 100]]]);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertSame('gl_unresolved', $bill->metadata['ingest']['review_code']);
    }

    public function test_ai_coding_stays_off_for_a_company_that_never_switched_it_on(): void
    {
        $this->suggest((string) $this->expenseAccount()->account_number, 0.95);

        [$log] = $this->ingest(['ncf' => 'B0100000123', 'line_items' => [['description' => 'Cloud hosting', 'line_total' => 100]]]);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
    }

    /**
     * @param array<string, mixed> $extra
     * @return array{0: PdfIngestLog, 1: Bill}
     */
    private function ingest(array $extra): array
    {
        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->dr,
                pdf: $this->createFilesystemRow(companiesId: $this->dr->getId()),
                messageId: 'dr-bill-' . uniqid(),
                fromEmail: 'facturas-' . uniqid() . '@caribe.test',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.8,
                extracted: $extra + [
                    'vendor_name' => 'Suministros del Caribe ' . uniqid(),
                    'vendor_tax_id' => '1-31-79432-1',
                    'bill_number' => 'F-' . uniqid(),
                    'currency' => 'DOP',
                    'subtotal' => 100.0,
                    'tax' => 18.0,
                    'total' => 118.0,
                ],
            )),
            hasher: new FakePdfContentHasher(),
        )->execute();

        return [$log, Bill::query()->findOrFail($log->linked_entity_id)];
    }

    private function expenseAccount(): Account
    {
        return Account::query()
            ->where('companies_id', $this->dr->getId())
            ->where('account_type', AccountTypeEnum::EXPENSE->value)
            ->where('is_deleted', false)
            ->orderByDesc('account_number')
            ->firstOrFail();
    }

    private function item(string $name, Account $account): Item
    {
        return Item::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->dr->getId(),
            'uuid' => (string) Str::uuid(),
            'item_number' => 'IT-' . uniqid(),
            'name' => $name,
            'type' => 'product',
            'default_expense_account_id' => $account->getId(),
            'is_active' => true,
            'source' => 'kanvas',
        ]);
    }

    private function suggest(string $accountNumber, float $confidence): void
    {
        $this->app->instance(ExpenseAccountSuggesterInterface::class, new class ($accountNumber, $confidence) implements ExpenseAccountSuggesterInterface {
            public function __construct(
                private readonly string $accountNumber,
                private readonly float $confidence,
            ) {
            }

            public function suggest(string $vendorName, array $lineDescriptions, array $accounts): array
            {
                return ['account_number' => $this->accountNumber, 'confidence' => $this->confidence, 'reasoning' => 'Hosting is software.'];
            }
        });
    }
}
