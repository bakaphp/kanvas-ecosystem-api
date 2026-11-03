<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssist\Supervisor;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\SalesAssist\Activities\SalesAssistAgentReachOutActivity;
use Kanvas\Connectors\SalesAssist\Supervisor\Jobs\RunSupervisorReviewJob;
use Kanvas\Connectors\SalesAssist\Supervisor\Neuron\SupervisorSaAgent;
use Kanvas\Connectors\SalesAssist\Supervisor\Neuron\Tools\AuditCompanyConfigurationTool;
use Kanvas\Connectors\SalesAssist\Supervisor\Neuron\Tools\GetConversationsForReviewTool;
use Kanvas\Connectors\SalesAssist\Supervisor\Neuron\Tools\RecordSupervisorFindingTool;
use Kanvas\Connectors\SalesAssist\Supervisor\Notifications\SupervisorFindingsNotification;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorConfigurationAuditService;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorFindingLedger;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorFollowUpAuditService;
use Kanvas\Connectors\SalesAssist\Supervisor\Services\SupervisorScheduleService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadType;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\AddLeadNoteTool;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Social\Messages\Models\AppModuleMessage;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use ReflectionMethod;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

final class SupervisorSaAgentTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
    }

    public function testTheTypeIsOfferedOnlyToItsOwnApp(): void
    {
        $this->artisan('kanvas:salesassist:setup-supervisor-agent-type', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();
        $this->artisan('kanvas:salesassist:setup-supervisor-agent-type', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();

        $types = AgentType::query()->where('handler', SupervisorSaAgent::class)->get();

        $this->assertCount(1, $types);
        $this->assertSame($this->kanvasApp->getId(), (int) $types->first()->apps_id);
    }

    public function testTheAgentCarriesItsToolsAndIgnoresRegistryGrants(): void
    {
        $type = AgentType::factory()->withAppId($this->kanvasApp->getId())->create(['handler' => SupervisorSaAgent::class]);
        $agent = Agent::factory()->withAppId($this->kanvasApp->getId())->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => auth()->user()->getId()]);

        $instance = new SupervisorSaAgent();
        $instance->setConfiguration(agent: $agent, user: auth()->user());

        $tools = new ReflectionMethod($instance, 'tools')->invoke($instance);

        $this->assertSame(
            [AuditCompanyConfigurationTool::class, GetConversationsForReviewTool::class, RecordSupervisorFindingTool::class],
            array_map(static fn (object $tool): string => $tool::class, $tools),
        );
    }

    public function testTheAuditReportsMissingSettingsWithoutValues(): void
    {
        $this->company->set('TWILIO_AUTH_TOKEN', 'top-secret-token-value');
        $this->company->del('adf_sources');

        $findings = new SupervisorConfigurationAuditService($this->kanvasApp, $this->company)->execute();

        $this->assertContains('setting.adf_sources_missing', array_column($findings, 'code'));
        $this->assertStringNotContainsString('top-secret-token-value', (string) json_encode($findings));
    }

    public function testAnAgentSettingPointingOutsideTheCompanyIsCritical(): void
    {
        $this->company->set('ai_assist_agent', 999999999);

        $findings = collect(new SupervisorConfigurationAuditService($this->kanvasApp, $this->company)->execute());
        $finding = $findings->firstWhere('code', 'setting.ai_assist_agent_foreign_agent');

        $this->assertSame('critical', $finding['severity'] ?? null);
    }

    public function testAProblemIsOpenedOnceAndResolvedWhenItGoesAway(): void
    {
        $ledger = new SupervisorFindingLedger($this->kanvasApp, $this->company);
        $finding = ['code' => 'setting.work_hours_missing', 'severity' => 'high', 'summary' => 'x', 'evidence' => []];

        $this->assertSame(['opened' => 1, 'resolved' => 0], $ledger->reconcileConfiguration([$finding]));
        $this->assertSame(['opened' => 0, 'resolved' => 0], $ledger->reconcileConfiguration([$finding]));
        $this->assertSame(['opened' => 0, 'resolved' => 1], $ledger->reconcileConfiguration([]));
        $this->assertSame([], $ledger->open());
    }

    public function testAFindingNeedsEvidenceFromTheLead(): void
    {
        $lead = Lead::factory()->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())->create();

        $result = new RecordSupervisorFindingTool()
            ->withContext($this->kanvasApp, $this->company, auth()->user())
            ->__invoke($lead->getId(), 'opt-out-ignored', 'critical', 'Texted after STOP.', '999999999');

        $this->assertSame('error', $result['status']);
        $this->assertSame([], new SupervisorFindingLedger($this->kanvasApp, $this->company)->open());
    }

    public function testOvernightFollowsTheDealersClock(): void
    {
        $schedule = new SupervisorScheduleService($this->company);

        $this->assertTrue($schedule->isOvernight(Carbon::parse('2026-11-02 00:00', 'America/Chicago')));
        $this->assertTrue($schedule->isOvernight(Carbon::parse('2026-11-02 05:59', 'America/Chicago')));
        $this->assertFalse($schedule->isOvernight(Carbon::parse('2026-11-02 06:00', 'America/Chicago')));
        $this->assertFalse($schedule->isOvernight(Carbon::parse('2026-11-02 21:00', 'America/Chicago')));
    }

    public function testADealerWithoutHoursGetsNoConversationReview(): void
    {
        $this->company->timezone = null;
        $this->company->del('work_hours');

        $this->assertFalse(
            new SupervisorScheduleService($this->company)->shouldReviewConversations(Carbon::parse('2026-11-02 08:00', 'UTC'))
        );
    }

    public function testAFindingWithItsEvidenceIsRecordedOnce(): void
    {
        [$lead, $messageId] = $this->leadWithActivity();
        $tool = new RecordSupervisorFindingTool()->withContext($this->kanvasApp, $this->company, auth()->user());

        $tool->__invoke($lead->getId(), 'opt-out-ignored', 'critical', 'Texted after STOP.', (string) $messageId);
        $second = $tool->__invoke($lead->getId(), 'opt-out-ignored', 'critical', 'Again.', (string) $messageId);

        $open = array_values(new SupervisorFindingLedger($this->kanvasApp, $this->company)->open());

        $this->assertCount(1, $open);
        $this->assertSame('no_policy', $open[0]['policy_status']);
        $this->assertStringContainsString('Already open', $second['message']);
    }

    public function testConversationsComeOnlyFromThisCompany(): void
    {
        [$lead] = $this->leadWithActivity();
        $other = Companies::factory()->create();

        $mine = new GetConversationsForReviewTool()
            ->withContext($this->kanvasApp, $this->company, auth()->user())
            ->__invoke(25);
        $theirs = new GetConversationsForReviewTool()
            ->withContext($this->kanvasApp, $other, auth()->user())
            ->__invoke(25);

        $this->assertContains($lead->getId(), array_column($mine['conversations'], 'lead_id'));
        $this->assertSame([], $theirs['conversations']);
    }

    public function testADealerOutsideItsWindowIsAuditedButNotReviewed(): void
    {
        $type = AgentType::factory()->withAppId($this->kanvasApp->getId())->create(['handler' => SupervisorSaAgent::class]);
        $agent = Agent::factory()->withAppId($this->kanvasApp->getId())->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => auth()->user()->getId()]);
        $this->company->del('work_hours');

        new RunSupervisorReviewJob($this->kanvasApp, $this->company, $agent)->handle();

        $types = Event::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_domain', SupervisorFindingLedger::SOURCE_DOMAIN)
            ->pluck('event_type')
            ->all();

        $this->assertContains(SupervisorFindingLedger::EVENT_OPENED, $types);
        $this->assertNotContains(SupervisorFindingLedger::EVENT_REVIEW_COMPLETED, $types);
    }

    public function testAReachOutRuleWithAnInactiveAgentIsCritical(): void
    {
        $this->company->del('agent_reach_out_default_agent_id');
        $rule = Rule::factory()->create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
        ]);
        $action = WorkflowAction::factory()->create([
            'name' => 'SalesAssistAgentReachOutActivity',
            'model_name' => SalesAssistAgentReachOutActivity::class,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);
        $step = RuleWorkflowAction::factory()->create(['actions_id' => $action->getId()]);
        RuleAction::factory()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $step->getId(),
            'weight' => 0,
        ]);

        $finding = collect(new SupervisorConfigurationAuditService($this->kanvasApp, $this->company)->execute())
            ->firstWhere('code', 'rule.reach_out_without_active_agent');

        $this->assertSame('critical', $finding['severity'] ?? null);
        $this->assertContains($rule->getId(), $finding['evidence']['rule_ids']);
    }

    public function testRuntimeToolsAreTheReadOnlySet(): void
    {
        $type = AgentType::factory()->withAppId($this->kanvasApp->getId())->create(['handler' => SupervisorSaAgent::class]);
        $agent = Agent::factory()->withAppId($this->kanvasApp->getId())->withCompanyId($this->company->getId())
            ->create(['agent_type_id' => $type->getId(), 'user_id' => auth()->user()->getId()]);
        $handler = new SupervisorSaAgent();
        $handler->setConfiguration(agent: $agent, user: auth()->user());

        [, $tools] = new ReflectionMethod($handler, 'resolveTools')->invoke($handler);
        $names = array_map(static fn (object $tool): string => (string) $tool->getName(), $tools);

        sort($names);

        $this->assertSame([
            'audit_company_configuration',
            'get_conversations_for_review',
            'get_current_time',
            'record_supervisor_finding',
            'search_knowledge',
        ], $names);
    }

    public function testACriticalFindingReachesEveryAgentDeveloperOnce(): void
    {
        Notification::fake();
        $this->artisan('kanvas:salesassist:setup-supervisor-agent-type', ['app_id' => $this->kanvasApp->getId()])->assertSuccessful();
        Bouncer::scope()->to(RolesEnums::getScope($this->kanvasApp));
        $developer = auth()->user();
        Bouncer::assign(RolesEnums::AGENT_DEVELOPER->value)->to($developer);
        Bouncer::refreshFor($developer);

        $ledger = new SupervisorFindingLedger($this->kanvasApp, $this->company);
        $finding = ['code' => 'setting.ai_assist_agent_foreign_agent', 'severity' => 'critical', 'summary' => 'x', 'evidence' => []];

        $ledger->reconcileConfiguration([$finding]);
        $ledger->reconcileConfiguration([$finding]);

        Notification::assertSentToTimes($developer, SupervisorFindingsNotification::class, 1);
    }

    public function testTheDigestSkipsCriticalsAndNothingNewSendsNothing(): void
    {
        $ledger = new SupervisorFindingLedger($this->kanvasApp, $this->company);
        Notification::fake();

        $this->assertSame([], $ledger->openNonCriticalSince(Carbon::now()->subDay()));

        $ledger->reconcileConfiguration([
            ['code' => 'a', 'severity' => 'critical', 'summary' => 'x', 'evidence' => []],
            ['code' => 'b', 'severity' => 'high', 'summary' => 'y', 'evidence' => []],
        ]);

        $this->assertSame(['b'], array_column($ledger->openNonCriticalSince(Carbon::now()->subDay()), 'code'));
    }

    public function testTheEmailBodyDoesNotRenderHtmlFromASummary(): void
    {
        $html = new SupervisorFindingsNotification($this->company, 'Subject', [[
            'code' => 'conversation.x', 'severity' => 'high', 'summary' => '<script>alert(1)</script>', 'evidence' => ['lead_id' => 7],
        ]])->getEmailContent();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('conversation.x', $html);
    }

    public function testAnActiveLeadTypeWithOverdueLeadsAndNoSendsIsReported(): void
    {
        $leadType = $this->leadType(true);
        $lead = $this->overdueLead($leadType, $this->followUpStage(true));
        $lead->emitLedgerEvent('lead.follow_up.skipped', payload: ['reason' => 'no_session']);

        $finding = $this->followUpFinding("follow_up.lead_type_{$leadType->getId()}_not_sending");

        $this->assertSame(['no_session' => 1], $finding['evidence']['recorded_reasons']);
        $this->assertContains($lead->getId(), $finding['evidence']['sample_lead_ids']);
    }

    public function testASendInTheWindowClearsTheFinding(): void
    {
        $leadType = $this->leadType(true);
        $stage = $this->followUpStage(true);
        $this->overdueLead($leadType, $stage);
        $this->overdueLead($leadType, $stage)->emitLedgerEvent('lead.follow_up.sent');

        $this->assertNull($this->followUpFinding("follow_up.lead_type_{$leadType->getId()}_not_sending"));
    }

    public function testALeadTypeWithoutActiveFollowUpIsNotReported(): void
    {
        $leadType = $this->leadType(false);
        $this->overdueLead($leadType, $this->followUpStage(true));

        $this->assertNull($this->followUpFinding("follow_up.lead_type_{$leadType->getId()}_not_sending"));
    }

    public function testALeadStillInsideTheStageIntervalIsNotOverdue(): void
    {
        $leadType = $this->leadType(true);
        $lead = $this->overdueLead($leadType, $this->followUpStage(true));
        $lead->set('follow_up_state', ['last_at' => Carbon::now()->toIso8601String(), 'count' => 1]);

        $this->assertNull($this->followUpFinding("follow_up.lead_type_{$leadType->getId()}_not_sending"));
    }

    public function testOpenLeadsOutsideAnyFollowUpStageAreReported(): void
    {
        $leadType = $this->leadType(true);
        $this->overdueLead($leadType, $this->followUpStage(false));

        $this->assertNotNull($this->followUpFinding("follow_up.lead_type_{$leadType->getId()}_no_enabled_stage"));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function followUpFinding(string $code): ?array
    {
        $findings = new SupervisorFollowUpAuditService($this->kanvasApp, $this->company)->execute();

        return collect($findings)->firstWhere('code', $code);
    }

    private function leadType(bool $followUpActive): LeadType
    {
        return LeadType::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'name' => 'Supervisor ' . uniqid(),
            'config' => ['internet_con_fu_active_default' => $followUpActive],
            'is_active' => 1,
        ]);
    }

    private function followUpStage(bool $enabled): PipelineStage
    {
        $pipeline = Pipeline::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => auth()->user()->getId(),
            'name' => 'Supervisor ' . uniqid(),
            'slug' => 'supervisor-' . uniqid(),
            'weight' => 0,
            'is_default' => 0,
        ]);

        return PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Day 1',
            'has_rotting_days' => 0,
            'rotting_days' => 0,
            'weight' => 0,
            'config' => [
                'follow_up' => ['enabled' => $enabled, 'mode' => 'time_based', 'time_based' => ['interval_minutes' => 60]],
            ],
        ]);
    }

    private function overdueLead(LeadType $leadType, PipelineStage $stage): Lead
    {
        // Set at creation: moving a saved lead to a stage restarts its interval.
        return Lead::factory()
            ->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())
            ->create([
                'leads_types_id' => $leadType->getId(),
                'pipeline_id' => $stage->pipelines_id,
                'pipeline_stage_id' => $stage->getId(),
                'created_at' => Carbon::now()->subDays(2),
            ]);
    }

    /**
     * @return array{Lead, int}
     */
    private function leadWithActivity(): array
    {
        $lead = Lead::factory()->withAppAndCompany($this->kanvasApp->getId(), $this->company->getId())->create();

        new AddLeadNoteTool()
            ->withContext($this->kanvasApp, $this->company, auth()->user())
            ->__invoke(lead_id: $lead->getId(), note: 'Customer asked about the price.');

        $messageId = (int) AppModuleMessage::query()
            ->where('system_modules', Lead::class)
            ->where('entity_id', $lead->getId())
            ->value('message_id');

        return [$lead, $messageId];
    }
}
