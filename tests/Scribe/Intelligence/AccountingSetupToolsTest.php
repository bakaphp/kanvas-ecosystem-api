<?php

declare(strict_types=1);

namespace Tests\Scribe\Intelligence;

use Illuminate\Support\Carbon;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Onboarding\Models\CompanyOnboarding;
use Kanvas\Companies\Onboarding\Models\OnboardingSection;
use Kanvas\Companies\Onboarding\Services\OnboardingStateService;
use Kanvas\Intelligence\Agents\Enums\ToolOutcomeEnum;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsPayableAgent;
use Kanvas\Intelligence\Agents\Neuron\Accounting\AccountsReceivableAgent;
use Kanvas\Intelligence\Agents\Neuron\Company\CompanyBrainAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\CheckAccountingSetupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ConfigureEcfRangeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ListEcfRangesTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\RunAccountingSetupTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\SetReminderCadenceTool;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Scribe\Delivery\DataTransferObject\DunningSchedule;
use Kanvas\Scribe\Setup\Services\AccountingSetupStateService;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\Scribe\ScribeTestCase;
use Tests\Scribe\Setup\Concerns\UsesFreshAccountingCompany;

final class AccountingSetupToolsTest extends ScribeTestCase
{
    use UsesFreshAccountingCompany;

    private const array SETUP_TOOLS = ['check_accounting_setup', 'list_ecf_ranges', 'configure_ecf_range'];

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'accounting', 'intelligence', 'crm', 'workflow', 'action_engine'];

    private string $prefix;

    protected function afterScribeSetUp(): void
    {
        config()->set('services.mailgun.domain', 'mg.kanvas.test');
        config()->set('services.mailgun.accounting_inbox_domain', null);
        $this->prefix = 'ast' . substr(uniqid(), -6) . '_';
    }

    public function test_check_lists_every_requirement_and_the_setup_task_it_closes(): void
    {
        $company = $this->freshCompany();
        $plan = $this->setupPlan($company, ['approver' => 'Confirm the approver']);

        $result = $this->tool(new CheckAccountingSetupTool(), $company)->__invoke();

        $this->assertTrue($result['success']);
        $this->assertFalse($result['ready']);
        $keys = array_column($result['items'], 'key');
        $this->assertContains('ledger', $keys);
        $this->assertContains('approver', $keys);
        $this->assertNotContains('ecf_ranges', $keys);
        $this->assertSame('missing', $result['items'][array_search('approver', $keys, true)]['status']);

        $this->assertCount(1, $result['setup_tasks']);
        $this->assertSame($this->prefix . 'Confirm the approver', $result['setup_tasks'][0]['title']);
        $this->assertSame('approver', $result['setup_tasks'][0]['requirement']);
        $this->assertFalse($result['setup_tasks'][0]['closes_now']);

        new AccountingSetupStateService()->setDefaultApprover($company, static::$cachedUser);
        $after = $this->tool(new CheckAccountingSetupTool(), $company)->__invoke();
        $this->assertTrue($after['setup_tasks'][0]['closes_now']);
        $this->assertSame(1, $plan->tasks()->count());
    }

    public function test_run_setup_creates_the_books_names_the_approver_and_closes_the_task(): void
    {
        $company = $this->freshCompany();
        $plan = $this->setupPlan($company, ['approver' => 'Confirm the approver']);

        $result = $this->tool(new RunAccountingSetupTool(), $company)->__invoke(approver_email: static::$cachedUser->email);

        $this->assertTrue($result['success']);
        $this->assertSame(static::$cachedUser->getId(), $result['approver_users_id']);
        $this->assertContains('ledger', array_column($result['steps'], 'step'));
        $readiness = array_column($result['readiness'], 'status', 'key');
        $this->assertSame('ready', $readiness['ledger']);
        $this->assertSame('ready', $readiness['approver']);
        $this->assertSame(static::$cachedUser->getId(), new AccountingSetupStateService()->defaultApprover($company)?->getId());

        $task = $plan->tasks()->firstOrFail();
        $this->assertSame(TaskStatusEnum::DONE->value, $task->status instanceof TaskStatusEnum ? $task->status->value : $task->status);

        $again = $this->tool(new RunAccountingSetupTool(), $company)->__invoke();
        $this->assertTrue($again['success']);
    }

    public function test_run_setup_refuses_a_non_admin_an_outsider_approver_and_a_bad_country(): void
    {
        $company = $this->freshCompany();

        $denied = $this->tool(new RunAccountingSetupTool(), $company, Users::factory()->create())->__invoke();
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $denied['outcome']);
        $this->assertFalse($denied['success']);

        $outsider = Users::factory()->create();
        $notMember = $this->tool(new RunAccountingSetupTool(), $company)->__invoke(approver_user_id: $outsider->getId());
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $notMember['outcome']);
        $this->assertStringContainsString('must be a user of this company', $notMember['error']);

        $unknownEmail = $this->tool(new RunAccountingSetupTool(), $company)->__invoke(approver_email: 'nobody-' . uniqid() . '@example.com');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $unknownEmail['outcome']);

        $badCountry = $this->tool(new RunAccountingSetupTool(), $company)->__invoke(country_code: 'DOM');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $badCountry['outcome']);

        $this->assertFalse(new AccountingSetupStateService()->hasRun($company));
    }

    public function test_configure_loads_a_range_lists_it_and_closes_the_ranges_task(): void
    {
        $company = $this->freshCompany('DO');
        $plan = $this->setupPlan($company, ['ecf_ranges' => 'Load the e-NCF ranges']);

        $empty = $this->tool(new ListEcfRangesTool(), $company)->__invoke();
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $empty['outcome']);
        $this->assertSame(0, $empty['count']);

        $result = $this->tool(new ConfigureEcfRangeTool(), $company)->__invoke(
            document_type: 'E31',
            first_number: 'E310000000001',
            last_number: 'E310000001000',
            expires_at: Carbon::today()->addYear()->toDateString(),
        );

        $this->assertTrue($result['success']);
        $this->assertSame('31', $result['range']['document_type']);
        $this->assertSame('E310000000001', $result['range']['first_number']);
        $this->assertSame('E310000001000', $result['range']['last_number']);
        $this->assertSame(1000, $result['range']['remaining']);
        $this->assertSame(1, $result['setup_tasks_closed']);
        $task = $plan->tasks()->firstOrFail();
        $this->assertSame(TaskStatusEnum::DONE->value, $task->status instanceof TaskStatusEnum ? $task->status->value : $task->status);

        $this->tool(new ConfigureEcfRangeTool(), $company)->__invoke(document_type: '32', first_number: '1', last_number: '500');

        $listed = $this->tool(new ListEcfRangesTool(), $company)->__invoke(active_only: true);
        $this->assertSame(2, $listed['count']);
        $this->assertSame('32', $listed['ranges'][0]['document_type']);
        $this->assertSame('E320000000001', $listed['ranges'][0]['next_number']);
        $this->assertNull($listed['ranges'][0]['expires_at']);
    }

    public function test_configure_refuses_a_non_admin_and_a_bad_range(): void
    {
        $company = $this->freshCompany('DO');

        $denied = $this->tool(new ConfigureEcfRangeTool(), $company, Users::factory()->create())
            ->__invoke(document_type: '31', first_number: '1', last_number: '100');
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $denied['outcome']);

        $backwards = $this->tool(new ConfigureEcfRangeTool(), $company)->__invoke(document_type: '31', first_number: '100', last_number: '1');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $backwards['outcome']);

        $wrongPrefix = $this->tool(new ConfigureEcfRangeTool(), $company)->__invoke(document_type: '31', first_number: 'E320000000001', last_number: '100');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $wrongPrefix['outcome']);

        $expired = $this->tool(new ConfigureEcfRangeTool(), $company)->__invoke(
            document_type: '31',
            first_number: '1',
            last_number: '100',
            expires_at: Carbon::today()->subDay()->toDateString(),
        );
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $expired['outcome']);

        $unknownType = $this->tool(new ConfigureEcfRangeTool(), $company)->__invoke(document_type: '99', first_number: '1', last_number: '100');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $unknownType['outcome']);

        $this->assertSame(0, $this->tool(new ListEcfRangesTool(), $company)->__invoke()['count']);
    }

    public function test_reminder_cadence_reads_changes_and_refuses(): void
    {
        $company = $this->freshCompany();

        $read = $this->tool(new SetReminderCadenceTool(), $company)->__invoke();
        $this->assertSame(ToolOutcomeEnum::NOOP->value, $read['outcome']);
        $this->assertSame(DunningSchedule::DEFAULT_STEPS, $read['cadence']['steps']);

        $changed = $this->tool(new SetReminderCadenceTool(), $company)->__invoke(steps: '14, -5, 0', reply_pause_days: 3);
        $this->assertTrue($changed['success']);
        $schedule = DunningSchedule::forCompany($company);
        $this->assertSame([-5, 0, 14], $schedule->steps);
        $this->assertSame(3, $schedule->replyPauseDays);
        $this->assertSame(DunningSchedule::DEFAULT_ESCALATE_AFTER_DAYS, $schedule->escalateAfterDays);

        $invalid = $this->tool(new SetReminderCadenceTool(), $company)->__invoke(steps: 'weekly');
        $this->assertSame(ToolOutcomeEnum::INVALID_ARGS->value, $invalid['outcome']);

        $denied = $this->tool(new SetReminderCadenceTool(), $company, Users::factory()->create())->__invoke(enabled: false);
        $this->assertSame(ToolOutcomeEnum::DENIED->value, $denied['outcome']);
        $this->assertTrue(DunningSchedule::forCompany($company)->enabled);
    }

    public function test_the_setup_agent_holds_the_setup_tools_once_the_company_has_accounting_agents(): void
    {
        $company = $this->freshCompany('DO');
        $brain = $this->accountingAgent($company, CompanyBrainAgent::class, Users::factory()->create());

        $before = $this->toolNames($this->handler(new CompanyBrainAgent(), $brain));
        $this->assertNotContains('check_accounting_setup', $before);
        $this->assertNotContains('run_accounting_setup', $before);

        $this->accountingAgent($company, AccountsPayableAgent::class, Users::factory()->create());

        $after = $this->toolNames($this->handler(new CompanyBrainAgent(), $brain));
        foreach ([...self::SETUP_TOOLS, 'run_accounting_setup', 'set_reminder_cadence'] as $name) {
            $this->assertContains($name, $after);
        }
        $this->assertSame(count($after), count(array_unique($after)));
    }

    public function test_ap_and_ar_hold_the_range_tools_only_for_a_dominican_company(): void
    {
        $dominican = $this->freshCompany('DO');
        $ap = $this->toolNames($this->handler(new AccountsPayableAgent(), $this->accountingAgent($dominican, AccountsPayableAgent::class, Users::factory()->create())));
        $ar = $this->toolNames($this->handler(new AccountsReceivableAgent(), $this->accountingAgent($dominican, AccountsReceivableAgent::class, Users::factory()->create())));

        foreach (self::SETUP_TOOLS as $name) {
            $this->assertContains($name, $ap);
            $this->assertContains($name, $ar);
        }
        $this->assertContains('set_reminder_cadence', $ar);
        $this->assertNotContains('set_reminder_cadence', $ap);
        $this->assertContains('run_accounting_setup', $ap);
        $this->assertContains('run_accounting_setup', $ar);

        $american = $this->freshCompany();
        $usAp = $this->toolNames($this->handler(new AccountsPayableAgent(), $this->accountingAgent($american, AccountsPayableAgent::class, Users::factory()->create())));
        $this->assertContains('check_accounting_setup', $usAp);
        $this->assertNotContains('configure_ecf_range', $usAp);
        $this->assertNotContains('list_ecf_ranges', $usAp);
    }

    public function test_ar_can_set_up_a_billing_contact_and_share_a_document_link_without_dangling_tool_names(): void
    {
        $ar = $this->handler(new AccountsReceivableAgent(), $this->accountingAgent($this->freshCompany(), AccountsReceivableAgent::class, Users::factory()->create()));
        $tools = new ReflectionMethod($ar, 'tools')->invoke($ar);
        $names = $this->toolNames($ar);

        $added = ['get_document_link', 'create_person', 'link_person_to_organization', 'tag_person', 'list_organization_people'];
        foreach ($added as $name) {
            $this->assertContains($name, $names);
        }

        $prose = '';
        foreach ($tools as $tool) {
            if (! in_array($tool->getName(), [...$added, 'send_invoice', 'send_quote'], true)) {
                continue;
            }
            $prose .= ' ' . $tool->getDescription();
            foreach ($tool->getProperties() as $property) {
                $prose .= ' ' . $property->getDescription();
            }
        }

        preg_match_all('/\b(?:get|search|find|create|update|set|add|send|list|upload|cancel|reschedule|reassign|stop|read|link|tag)_[a-z0-9_]+\b/', $prose, $matches);
        $this->assertSame([], array_values(array_diff(array_unique($matches[0]), $names)));

        $this->assertNotContains('get_document_link', $this->toolNames($this->handler(new AccountsPayableAgent(), $this->accountingAgent($this->freshCompany(), AccountsPayableAgent::class, Users::factory()->create()))));
    }

    /**
     * @template T of object
     * @param T $tool
     * @return T
     */
    private function tool(object $tool, Companies $company, ?Users $user = null): object
    {
        return $tool->withContext($this->kanvasApp, $company, $user ?? static::$cachedUser);
    }

    private function handler(object $handler, $agent): object
    {
        $handler->setConfiguration($agent, null, static::$cachedUser);

        return $handler;
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

    /**
     * An onboarding row whose setup plan has one task per readiness key, matched by title as in production.
     *
     * @param array<string, string> $tasks readiness key => task title
     */
    private function setupPlan(Companies $company, array $tasks): Plan
    {
        $steps = [];
        $taskData = [];
        $sequence = 1;
        foreach ($tasks as $readiness => $title) {
            $steps[] = ['title' => $this->prefix . $title, 'readiness' => $readiness];
            $taskData[] = new TaskData(plan: null, title: $this->prefix . $title, sequence: $sequence++);
        }

        OnboardingSection::query()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'slug' => $this->prefix . 'ap',
            'label' => 'AP',
            'title' => 'AP',
            'description' => 'Description',
            'is_available' => true,
            'display_order' => 1,
            'is_active' => true,
            'config' => [
                'agents' => [],
                'modules' => [],
                'requirements' => [],
                'capabilities' => [],
                'setup_steps' => $steps,
                'setup' => 'accounting',
            ],
        ]);

        /** @var CompanyOnboarding $row */
        $row = new OnboardingStateService()->forCompany($this->kanvasApp, $company, static::$cachedUser);
        $plan = new CreatePlanAction(
            new PlanData(
                app: $this->kanvasApp,
                company: $company,
                title: 'Set up AP',
                planType: 'onboarding',
                user: static::$cachedUser,
                status: PlanStatusEnum::ACTIVE,
            ),
            $taskData,
        )->execute();
        $row->dataSet('setup_plan_id', $plan->getId());

        return $plan;
    }
}
