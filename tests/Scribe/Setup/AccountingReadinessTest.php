<?php

declare(strict_types=1);

namespace Tests\Scribe\Setup;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CreateApBillTool;
use Kanvas\Scribe\Banking\Actions\CreateBankAccountAction;
use Kanvas\Scribe\Banking\DataTransferObject\BankAccount as BankAccountData;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Setup\Actions\SetUpAccountingAgentAction;
use Kanvas\Scribe\Setup\Services\AccountingReadinessService;
use ReflectionMethod;
use Tests\Scribe\Approvals\Concerns\SeedsBillApprovals;
use Tests\Scribe\ScribeTestCase;
use Tests\Scribe\Setup\Concerns\UsesFreshAccountingCompany;
use Tests\Stubs\Scribe\FakeEcfProvider;

final class AccountingReadinessTest extends ScribeTestCase
{
    use SeedsBillApprovals;
    use UsesFreshAccountingCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'workflow', 'action_engine'];

    protected function afterScribeSetUp(): void
    {
        config()->set('services.mailgun.domain', 'mg.kanvas.test');
        config()->set('services.mailgun.accounting_inbox_domain', null);
        config()->set('scribe.ecf_providers.fake', FakeEcfProvider::class);
    }

    public function test_a_company_that_never_ran_setup_is_missing_everything_but_the_optional_lines(): void
    {
        $items = $this->statuses($this->freshCompany());

        $this->assertSame([
            'ledger' => 'missing',
            'fiscal_periods' => 'missing',
            'inbox' => 'missing',
            'approver' => 'missing',
            'bank' => 'missing',
            'erp' => 'ready',
            'slack' => 'missing',
        ], $items);
    }

    public function test_after_setup_the_books_and_approver_are_ready_and_the_inbox_waits_for_its_route(): void
    {
        $company = $this->freshCompany();
        new SetUpAccountingAgentAction($this->kanvasApp, $company, static::$cachedUser)->execute();

        $readiness = new AccountingReadinessService()->check($this->kanvasApp, $company);

        $this->assertSame('ready', $readiness['ledger']->status->value);
        $this->assertSame('ready', $readiness['fiscal_periods']->status->value);
        $this->assertSame('ready', $readiness['approver']->status->value);
        $this->assertSame('degraded', $readiness['inbox']->status->value);
        $this->assertNotNull($readiness['inbox']->fixHow);

        $line = new AccountingReadinessService()->promptLine($this->kanvasApp, $company);
        $this->assertStringContainsString('@mg.kanvas.test', $line);
        $this->assertStringContainsString('Bank feed: MISSING', $line);
    }

    public function test_a_bank_account_with_no_statement_is_degraded(): void
    {
        $company = $this->freshCompany();
        new SetUpAccountingAgentAction($this->kanvasApp, $company, static::$cachedUser)->execute();
        new CreateBankAccountAction(
            data: new BankAccountData(
                app: $this->kanvasApp,
                company: $company,
                account_name: 'Popular DOP',
                gl_account_id: (int) Account::query()
                    ->where('companies_id', $company->getId())
                    ->where('account_sub_type', AccountSubTypeEnum::CASH_CHECKING->value)
                    ->value('id'),
                currency: 'USD',
                account_number_last4: '1234',
                institution_name: 'Banco Popular',
                source: 'kanvas',
            ),
            user: static::$cachedUser,
        )->execute();

        $bank = new AccountingReadinessService()->check($this->kanvasApp, $company)['bank'];

        $this->assertSame('degraded', $bank->status->value);
        $this->assertStringContainsString('Popular DOP', $bank->detail);
    }

    public function test_a_dominican_company_has_an_ecf_line_that_follows_provider_and_range(): void
    {
        $company = $this->freshCompany('DO');
        $readiness = new AccountingReadinessService();

        $this->assertSame('missing', $readiness->check($this->kanvasApp, $company)['ecf_provider']->status->value);

        $company->set(EcfConfigurationEnum::ECF_PROVIDER->value, 'fake');
        $this->assertSame('degraded', $readiness->check($this->kanvasApp, $company)['ecf_provider']->status->value);

        new DocumentNumberAllocatorService()->configureRange(
            appsId: $this->kanvasApp->getId(),
            companiesId: $company->getId(),
            documentType: DocumentTypeEnum::forFiscal(EcfTypeEnum::CREDITO_FISCAL),
            prefix: EcfTypeEnum::CREDITO_FISCAL->series(),
            rangeStart: 1,
            rangeEnd: 1000,
            expiresAt: Carbon::parse('2028-12-31'),
            padding: 10,
        );
        $line = $readiness->check($this->kanvasApp, $company)['ecf_provider'];
        $this->assertSame('degraded', $line->status->value);
        $this->assertStringContainsString('no issuer RNC', $line->detail);

        $company->set(EcfConfigurationEnum::ECF_ISSUER_TAX_ID->value, '101010101');
        $this->assertSame('ready', $readiness->check($this->kanvasApp, $company)['ecf_provider']->status->value);
    }

    public function test_the_agents_get_the_readiness_line_and_lose_tools_whose_dependency_is_missing(): void
    {
        $company = $this->freshCompany();
        $readiness = new AccountingReadinessService();

        $ar = $this->accountingAgent($company, AccountsReceivableAgent::class);
        $handler = new AccountsReceivableAgent();
        $handler->setConfiguration(agent: $ar, user: static::$cachedUser);

        $names = $this->toolNames($handler);
        $this->assertNotContains('check_rnc', $names);
        $this->assertContains('recent_inbox_arrivals', $names);
        if (! $readiness->gmailConnected($this->kanvasApp)) {
            $this->assertNotContains('list_emails', $names);
        }
        $this->assertStringContainsString('## Your setup right now', $handler->instructions());

        $company->set(EcfConfigurationEnum::ECF_PROVIDER->value, 'fake');
        $handler->setConfiguration(agent: $ar, user: static::$cachedUser);
        $this->assertContains('check_rnc', $this->toolNames($handler));

        $ap = new AccountsPayableAgent();
        $ap->setConfiguration(agent: $this->accountingAgent($company, AccountsPayableAgent::class), user: static::$cachedUser);
        $this->assertContains('recent_inbox_arrivals', $this->toolNames($ap));
    }

    public function test_create_ap_bill_with_approve_immediately_opens_one_request_and_leaves_the_bill_pending(): void
    {
        Notification::fake();
        $this->seedBillPolicy();
        $vendor = $this->seedTestOrganization('Approve Now Vendor ' . uniqid());
        $this->linkApprover($vendor);
        $accountCode = (string) Account::query()
            ->where('id', $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS))
            ->value('account_number');

        $result = new CreateApBillTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(
                vendor_name: $vendor->name,
                memo: 'Approve now test',
                invoice_number: 'NOW-' . uniqid(),
                amount: 90.0,
                gl_account_number: $accountCode,
                approve_immediately: true,
            );

        $bill = Bill::query()->findOrFail($result['bill_id']);
        $this->assertSame(BillDocumentStatusEnum::PENDING_APPROVAL, $bill->document_status);
        $this->assertSame('pending_approval', $result['document_status']);
        $this->assertArrayHasKey('approval_note', $result);
        $this->assertSame(1, $bill->approvalRequests()->count());
        $this->assertNotNull($bill->pendingApproval());
    }

    /**
     * @return array<string, string>
     */
    private function statuses(Companies $company): array
    {
        return array_map(
            fn ($item): string => $item->status->value,
            new AccountingReadinessService()->check($this->kanvasApp, $company),
        );
    }

    /**
     * @return list<string>
     */
    private function toolNames(object $handler): array
    {
        $tools = new ReflectionMethod($handler, 'tools')->invoke($handler);

        return array_values(array_map(
            fn (object $tool): string => method_exists($tool, 'getName') ? $tool->getName() : $tool::class,
            $tools,
        ));
    }
}
