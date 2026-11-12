<?php

declare(strict_types=1);

namespace Tests\Scribe\Setup;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\ConfigurationEnum as MailgunConfigurationEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Enums\CustomFieldEnum as MailgunCustomFieldEnum;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\AccountingInboxService;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Actions\HireAgentAction;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum as EcfConfigurationEnum;
use Kanvas\Scribe\Ledger\Enums\ExternalGlSourceEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Setup\Actions\SetUpAccountingAgentAction;
use Kanvas\Scribe\Setup\Enums\AccountingSetupConfigurationEnum;
use Kanvas\Scribe\Setup\Jobs\SetUpAccountingAgentJob;
use Kanvas\Scribe\Setup\Services\AccountingSetupStateService;
use Kanvas\Users\Models\Users;
use Tests\Scribe\ScribeTestCase;
use Tests\Scribe\Setup\Concerns\UsesFreshAccountingCompany;
use Tests\Stubs\Scribe\FakeEcfProvider;

final class SetUpAccountingAgentActionTest extends ScribeTestCase
{
    use UsesFreshAccountingCompany;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'workflow', 'action_engine'];

    protected function afterScribeSetUp(): void
    {
        config()->set('services.mailgun.domain', 'mg.kanvas.test');
        config()->set('services.mailgun.accounting_inbox_domain', null);
    }

    public function test_it_sets_up_ledger_periods_policies_and_the_inbox_with_the_given_approver(): void
    {
        $company = $this->freshCompany('US', 'Globex Setup ' . uniqid());

        $state = $this->runSetup($company);

        $this->assertSame('US', $state['country']);
        foreach (['ledger', 'fiscal_periods', 'approval_policies', 'inbox', 'agent_mailboxes', 'defaults', 'collections_defaults', 'sow'] as $step) {
            $this->assertSame('done', $state['steps'][$step]['status'], $step);
        }
        $this->assertSame('skipped', $state['steps']['ecf_policies']['status']);
        $this->assertSame(DunningSchedule::defaults(), $company->get(DunningSchedule::SETTING));
        $this->assertGreaterThan(0, $this->accounts($company));
        $this->assertGreaterThan(12, $state['steps']['fiscal_periods']['months_opened']);

        $policy = $this->policy($company, 'approve_bill');
        $this->assertSame('explicit_users', $policy->fallback_resolver);
        $this->assertSame([static::$cachedUser->getId()], $policy->fallback_config['users_id']);
        $this->assertSame(static::$cachedUser->getId(), new AccountingSetupStateService()->defaultApprover($company)?->getId());
        $this->assertTrue((bool) $company->get(AccountingSetupConfigurationEnum::AI_EXPENSE_CODING->value));

        $address = new AccountingInboxService()->storedAddress($this->kanvasApp, $company);
        $this->assertNotNull($address);
        $this->assertMatchesRegularExpression('/^accounting-globex-setup-[a-z0-9-]+@mg\.kanvas\.test$/', $address);
        $this->assertNull($state['steps']['inbox']['route_id']);
        $this->assertNotNull($state['steps']['inbox']['route_error']);
        $this->assertSame($state, new AccountingSetupStateService()->state($company));
    }

    public function test_with_no_domain_to_receive_on_the_inbox_step_fails_and_creates_no_receiver(): void
    {
        config()->set('services.mailgun.domain', null);
        $company = $this->freshCompany();

        $state = $this->runSetup($company);

        $this->assertSame('failed', $state['steps']['inbox']['status']);
        $this->assertStringContainsString('No Mailgun domain', $state['steps']['inbox']['error']);
        $this->assertNull(new AccountingInboxService()->receiverFor($this->kanvasApp, $company));
        $this->assertSame('done', $state['steps']['ledger']['status']);
    }

    public function test_a_second_run_creates_nothing_new_and_keeps_the_inbox_address(): void
    {
        $company = $this->freshCompany();

        $first = $this->runSetup($company);
        $accounts = $this->accounts($company);
        $second = $this->runSetup($company);

        $this->assertSame($accounts, $this->accounts($company));
        $this->assertSame(0, $second['steps']['ledger']['accounts_inserted']);
        $this->assertSame(0, $second['steps']['fiscal_periods']['months_opened']);
        $this->assertSame($first['steps']['inbox']['receiver_id'], $second['steps']['inbox']['receiver_id']);
        $this->assertSame($first['steps']['inbox']['address'], $second['steps']['inbox']['address']);
        $this->assertFalse(collect($second['steps']['approval_policies']['policies'])->contains('created', true));
        $this->assertSame(1, ApprovalPolicy::query()->where('companies_id', $company->getId())->where('approval_type', 'approve_bill')->count());
    }

    public function test_an_erp_that_owns_the_books_skips_the_chart(): void
    {
        $company = $this->freshCompany();
        $company->set(ExternalGlSourceEnum::ACUMATICA->value, 1);

        $state = $this->runSetup($company);

        $this->assertSame('skipped', $state['steps']['ledger']['status']);
        $this->assertSame('erp_owns_gl', $state['steps']['ledger']['reason']);
        $this->assertSame(0, $this->accounts($company));
    }

    public function test_a_dominican_company_also_gets_the_ecf_policies_with_the_approver_as_fallback(): void
    {
        $company = $this->freshCompany('DO');

        $state = $this->runSetup($company);

        $this->assertSame('DO', $state['country']);
        $this->assertSame('done', $state['steps']['ecf_policies']['status']);
        $policy = $this->policy($company, 'approve_credit_note');
        $this->assertSame('explicit_users', $policy->fallback_resolver);
        $this->assertSame('origin', $policy->steps[0]['when']['field']);
    }

    public function test_a_company_with_no_country_but_a_dominican_provider_is_set_up_as_dominican(): void
    {
        config()->set('scribe.ecf_providers.fake', FakeEcfProvider::class);
        FakeEcfProvider::reset();
        $company = $this->freshCompany(null);
        $company->set(EcfConfigurationEnum::ECF_PROVIDER->value, 'fake');

        try {
            $state = $this->runSetup($company);
        } finally {
            $company->del(EcfConfigurationEnum::ECF_PROVIDER->value);
        }

        $this->assertSame('DO', $state['country']);
        $this->assertSame('done', $state['steps']['ecf_policies']['status']);
    }

    public function test_a_country_without_an_e_invoicing_regime_skips_the_fiscal_policies(): void
    {
        $state = $this->runSetup($this->freshCompany('US'));

        $this->assertSame('skipped', $state['steps']['ecf_policies']['status']);
        $this->assertSame('no_e_invoicing_regime', $state['steps']['ecf_policies']['reason']);
    }

    public function test_a_new_approver_replaces_the_default_but_never_a_role_someone_chose(): void
    {
        $company = $this->freshCompany();
        $this->runSetup($company);
        $invoicePolicy = $this->policy($company, 'approve_invoice');
        $invoicePolicy->fallback_resolver = 'role';
        $invoicePolicy->fallback_config = ['role' => 'Finance'];
        $invoicePolicy->saveOrFail();

        $other = Users::factory()->create();
        new SetUpAccountingAgentAction($this->kanvasApp, $company, $other)->execute();

        $this->assertSame([$other->getId()], $this->policy($company, 'approve_bill')->fallback_config['users_id']);
        $this->assertSame('role', $this->policy($company, 'approve_invoice')->fallback_resolver);
        $this->assertSame($other->getId(), new AccountingSetupStateService()->defaultApprover($company)?->getId());
    }

    public function test_the_route_is_created_on_the_platform_inbound_domain_when_mailgun_is_configured(): void
    {
        config()->set('services.mailgun.secret', 'key-test');
        config()->set('services.mailgun.accounting_inbox_domain', 'in.kanvas.test');
        Http::fake([
            'api.mailgun.net/v3/routes?*' => Http::response(['items' => []]),
            'api.mailgun.net/v3/routes' => Http::response(['route' => ['id' => 'route-123']]),
        ]);
        $company = $this->freshCompany('US', 'Initech Books');
        $company->set(MailgunConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-test');

        $state = $this->runSetup($company);

        $this->assertMatchesRegularExpression('/^accounting@initech-books(-\d+)?\.in\.kanvas\.test$/', $state['steps']['inbox']['address']);
        $this->assertSame('route-123', $state['steps']['inbox']['route_id']);
        $this->assertNull($state['steps']['inbox']['route_error']);
        $expression = 'match_recipient("' . $state['steps']['inbox']['address'] . '")';
        Http::assertSent(fn ($request): bool => $request->method() === 'POST'
            && collect($request->data())->contains(fn ($part): bool => ($part['contents'] ?? null) === $expression));
    }

    public function test_each_step_is_saved_as_it_finishes_so_a_crash_leaves_a_checkpoint(): void
    {
        config()->set('services.mailgun.secret', 'key-test');
        config()->set('services.mailgun.accounting_inbox_domain', 'in.kanvas.test');
        $company = $this->freshCompany('US', 'Checkpoint Books');
        $company->set(MailgunConfigurationEnum::WEBHOOK_SIGNING_KEY->value, 'signing-test');

        $duringInbox = null;
        Http::fake(function () use ($company, &$duringInbox) {
            $duringInbox ??= new AccountingSetupStateService()->state(Companies::find($company->getId()));

            return Http::response(['items' => [], 'route' => ['id' => 'route-1']]);
        });

        $state = $this->runSetup($company);

        $this->assertIsArray($duringInbox, 'The setup reached the inbox step.');
        $this->assertSame('inbox', $duringInbox['running_step']);
        $this->assertArrayNotHasKey('ran_at', $duringInbox);
        foreach (['ledger', 'fiscal_periods', 'approval_policies', 'ecf_policies'] as $step) {
            $this->assertArrayHasKey($step, $duringInbox['steps'], "{$step} was saved before the inbox step ran.");
        }
        $this->assertArrayNotHasKey('defaults', $duringInbox['steps']);

        $this->assertArrayNotHasKey('running_step', $state);
        $this->assertSame($state, new AccountingSetupStateService()->state($company));
    }

    public function test_the_ap_and_ar_agent_mailboxes_are_opened_once(): void
    {
        $company = $this->freshCompany();
        $ap = $this->accountingAgent($company, AccountsPayableAgent::class);
        $ap->set(MailgunCustomFieldEnum::MAILBOX_ADDRESS->value, 'ap@acme.test');
        $ap->set(MailgunCustomFieldEnum::MAILBOX_ACCESS->value, 'restricted');

        $this->runSetup($company);
        $this->assertSame('open', $ap->refresh()->get(MailgunCustomFieldEnum::MAILBOX_ACCESS->value));

        $ap->set(MailgunCustomFieldEnum::MAILBOX_ACCESS->value, 'restricted');
        $this->runSetup($company);
        $this->assertSame('restricted', $ap->refresh()->get(MailgunCustomFieldEnum::MAILBOX_ACCESS->value));
    }

    public function test_hiring_an_accounting_agent_queues_the_setup_with_the_hirer_as_approver(): void
    {
        Queue::fake();
        $company = $this->freshCompany();
        $type = AgentType::factory()->withAppId($this->kanvasApp->getId())->create(['provider' => 'neuron', 'handler' => AccountsReceivableAgent::class]);

        new HireAgentAction(
            app: $this->kanvasApp,
            company: $company,
            hiredBy: static::$cachedUser,
            hiredByAgent: null,
            agentType: $type,
            name: 'Receivables ' . uniqid(),
            role: 'AR',
            instructions: 'Collect what customers owe.',
        )->execute();

        Queue::assertPushed(
            SetUpAccountingAgentJob::class,
            fn (SetUpAccountingAgentJob $job): bool => $job->company->getId() === $company->getId()
                && $job->approver?->getId() === static::$cachedUser->getId()
        );
    }

    public function test_hiring_any_other_agent_queues_nothing(): void
    {
        Queue::fake();
        $type = AgentType::factory()->withAppId($this->kanvasApp->getId())->create(['provider' => 'neuron', 'handler' => 'Kanvas\\Intelligence\\Agents\\Neuron\\KanvasGenericNeuronAgent']);

        new HireAgentAction(
            app: $this->kanvasApp,
            company: $this->freshCompany(),
            hiredBy: static::$cachedUser,
            hiredByAgent: null,
            agentType: $type,
            name: 'Helper ' . uniqid(),
            role: 'Helper',
            instructions: 'Help.',
        )->execute();

        Queue::assertNotPushed(SetUpAccountingAgentJob::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function runSetup(Companies $company): array
    {
        return new SetUpAccountingAgentAction($this->kanvasApp, $company, static::$cachedUser)->execute();
    }

    private function accounts(Companies $company): int
    {
        return Account::query()->where('companies_id', $company->getId())->where('is_deleted', false)->count();
    }

    private function policy(Companies $company, string $type): ApprovalPolicy
    {
        return ApprovalPolicy::query()
            ->where('companies_id', $company->getId())
            ->where('approval_type', $type)
            ->notDeleted()
            ->firstOrFail();
    }
}
