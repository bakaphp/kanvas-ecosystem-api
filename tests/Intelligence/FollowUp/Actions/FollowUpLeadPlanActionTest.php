<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Actions\EndLeadFollowUpPlanAction;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\Actions\SetLeadFollowUpPlanAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpOutcome;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpOutcomeKindEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Notifications\LeadFollowUpPlanEndedNotification;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\Users\Models\Users;
use Tests\Intelligence\Tools\ReadsLeadNotes;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\TestCase;

/**
 * A person's plan through FollowUpLeadAction: what it replaces, what it keeps, how it ends.
 */
class FollowUpLeadPlanActionTest extends TestCase
{
    use DatabaseTransactions;
    use ReadsLeadNotes;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'workflow'];

    private const string BODY = 'This 2025 Mercedes is available. Please contact me regarding details.';

    private Apps $testApp;

    private Companies $company;

    private Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Notification::fake();
        EventFacade::fake([KnowledgeIndexRequested::class]);
        FollowUpAgentStub::reset();

        $this->testApp = app(Apps::class);
        $this->user = auth()->user();
        $this->company = $this->user->getCurrentCompany();
        $this->company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user->getId());

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
    }

    public function testPlanSendsTheFixedBodyWithoutTheAgentOnAStageWithoutFollowUp(): void
    {
        $lead = $this->seedLead(stageConfig: null);
        $this->setPlan($lead);
        $this->seedSession($lead);

        $outcome = $this->followUp($lead);

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');
        $this->assertSame(self::BODY . "\n\n" . SmsOptOutNoticeService::NOTICE, $outcome->message);
        $this->assertSame([], FollowUpAgentStub::$lastReceivedMessages, 'A fixed body never calls the agent.');

        $lead->refresh();
        $this->assertSame(1, $lead->getFollowUpStateCount());
        $this->assertNotNull($lead->getFollowUpPlan(), 'Six attempts left: the plan keeps running.');

        $sent = $this->ledger($lead, 'lead.follow_up.sent');
        $this->assertSame('plan', $sent->payload['source']);
        $this->assertSame('plan', $sent->payload['reason']);
    }

    public function testTheLastAttemptEndsThePlanAndTellsWhoSetIt(): void
    {
        $lead = $this->seedLead(stageConfig: null);
        $this->setPlan($lead, maxAttempts: 1);
        $this->seedSession($lead);

        $outcome = $this->followUp($lead);

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');

        $lead->refresh();
        $this->assertNull($lead->getFollowUpPlan());
        $ended = $lead->getLastFollowUpPlan();
        $this->assertSame(ManualFollowUpPlan::REASON_EXHAUSTED, $ended->doneReason);
        $this->assertSame(1, $ended->attemptsSent);
        $this->assertSame(0, $lead->getFollowUpStateCount(), 'The stage config starts from zero again.');
        $this->assertSame('exhausted', $this->ledger($lead, 'lead.follow_up.plan.ended')->payload['reason']);
        Notification::assertSentTo($this->user, LeadFollowUpPlanEndedNotification::class);
    }

    public function testAReplyAfterThePlanWasSetEndsItAtTheGate(): void
    {
        $lead = $this->seedLead(stageConfig: null);
        $this->setPlan($lead);
        $this->seedSession($lead);
        $this->seedInbound($lead, Carbon::now()->addSecond());

        $outcome = $this->followUp($lead);

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('plan_replied', $outcome->reason);

        $lead->refresh();
        $this->assertNull($lead->getFollowUpPlan());
        $this->assertSame(ManualFollowUpPlan::REASON_REPLIED, $lead->getLastFollowUpPlan()->doneReason);
        Notification::assertNothingSent();
    }

    public function testAReplyBeforeThePlanWasSetDoesNotEndIt(): void
    {
        $lead = $this->seedLead(stageConfig: null);
        $this->seedInbound($lead, Carbon::now()->subHour());
        $this->setPlan($lead);
        $this->seedSession($lead);

        $outcome = $this->followUp($lead);

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');
        $this->assertNotNull($lead->refresh()->getFollowUpPlan());
    }

    public function testAPlanWaitsForItsStartThenForTheInterval(): void
    {
        $lead = $this->seedLead(stageConfig: null);
        $this->setPlan($lead, startAt: Carbon::now()->addDay());
        $this->seedSession($lead);

        $outcome = $this->followUp($lead);
        $this->assertSame('plan_not_started', $outcome->reason);

        $this->setPlan($lead);
        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $this->followUp($lead)->kind);

        $second = $this->followUp($lead->refresh());
        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $second->kind);
        $this->assertSame('too_soon', $second->reason);
    }

    public function testTheMuteAndTheFollowUpToggleStillGateAPlan(): void
    {
        $lead = $this->seedLead(stageConfig: null);
        $this->setPlan($lead);
        $this->seedSession($lead);

        $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::OFF->value);
        $this->assertSame('ai_mode_off', $this->followUp($lead)->reason);

        $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::FULL_ON->value);
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);
        $this->assertSame('lead_follow_up_disabled', $this->followUp($lead)->reason);
        $this->assertNotNull($lead->refresh()->getFollowUpPlan(), 'The toggle pauses a plan; it does not end it.');
    }

    public function testSettingAPlanTurnsTheFollowUpToggleOnAndCancellingRestoresTheStage(): void
    {
        $lead = $this->seedLead(stageConfig: [
            'follow_up' => [
                'enabled' => true,
                'mode' => 'time_based',
                'time_based' => ['interval_minutes' => 1440],
                'max_retries' => 5,
                'channels' => [['type' => 'email', 'enabled' => true, 'template_name' => null]],
            ],
            'stage_meta' => ['is_terminal' => false],
        ]);
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::NO_FOLLOW_UP->value);
        $lead->bumpFollowUp(['email'], null);

        $this->setPlan($lead);

        $this->assertTrue($lead->isAiFollowUpEnabled());
        $this->assertSame(0, $lead->getFollowUpStateCount(), 'A plan restarts the attempts.');
        $eligibility = new LeadFollowUpEligibilityService();
        $this->assertSame(7, $eligibility->configFor($lead)->maxRetries);
        $this->assertSame('sms', $eligibility->configFor($lead)->channels[0]->type);
        $this->assertNotNull($this->ledger($lead, 'lead.follow_up.plan.set'));
        $this->assertStringContainsString('Manual follow-up plan set by', $this->latestLeadNoteContent($lead));

        new EndLeadFollowUpPlanAction($lead, ManualFollowUpPlan::REASON_CANCELLED)->execute();

        $this->assertSame(5, $eligibility->configFor($lead)->maxRetries);
        $this->assertSame('email', $eligibility->configFor($lead)->channels[0]->type);
        $this->assertSame('cancelled', $lead->getLastFollowUpPlan()->doneReason);
        Notification::assertNothingSent();
    }

    private function followUp(Lead $lead): FollowUpOutcome
    {
        return new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();
    }

    private function setPlan(Lead $lead, int $maxAttempts = 7, ?Carbon $startAt = null): ManualFollowUpPlan
    {
        $now = Carbon::now();

        return new SetLeadFollowUpPlanAction(
            $lead,
            new ManualFollowUpPlan(
                channel: 'sms',
                body: self::BODY,
                intervalMinutes: 1440,
                maxAttempts: $maxAttempts,
                startAt: $startAt ?? $now,
                setAt: $now,
                setByUsersId: $this->user->getId(),
            ),
            $this->user,
        )->execute();
    }

    private function seedLead(?array $stageConfig): Lead
    {
        $pipeline = Pipeline::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'system_modules_id' => 0,
            'name' => 'Plan Pipeline',
            'is_default' => 0,
        ]);
        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Stage',
            'weight' => 1,
            'config' => $stageConfig,
        ]);

        return Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create([
                'pipeline_id' => $pipeline->getId(),
                'pipeline_stage_id' => $stage->getId(),
                'leads_owner_id' => $this->user->getId(),
                'created_at' => Carbon::now()->subDays(30),
            ]);
    }

    private function seedFollowUpAgent(): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->testApp->getId())
            ->create(['provider' => 'neuron', 'handler' => FollowUpAgentStub::class]);

        return Agent::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->create([
                'name' => AgentEnum::FOLLOW_UP_ENGAGER->value,
                'agent_type_id' => $agentType->getId(),
                'user_id' => $this->user->getId(),
                'is_active' => true,
                'role' => ['background' => [], 'steps' => [], 'output' => ''],
            ]);
    }

    private function seedSession(Lead $lead): Session
    {
        MessageType::firstOrCreate(
            ['apps_id' => $this->testApp->getId(), 'languages_id' => 1, 'verb' => 'sms'],
            ['name' => 'Sms']
        );
        $channel = Channel::firstOrCreate(
            ['apps_id' => $this->testApp->getId(), 'companies_id' => $this->company->getId(), 'slug' => 'plan-sms-' . $lead->getId()],
            ['name' => 'Plan SMS', 'description' => 'test', 'users_id' => $this->user->getId()]
        );

        return Session::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'channel_id' => $channel->getId(),
            'entity_namespace' => People::class,
            'entity_id' => $lead->people_id,
            'uuid' => 'twilio-' . (string) Str::uuid(),
            'user' => [],
            'content' => [],
            'is_deleted' => 0,
        ]);
    }

    private function seedInbound(Lead $lead, Carbon $at): void
    {
        $channel = Channel::firstOrCreate(
            ['apps_id' => $this->testApp->getId(), 'companies_id' => $this->company->getId(), 'slug' => 'plan-inbound-' . $lead->getId()],
            [
                'name' => 'Inbound',
                'description' => 'test',
                'users_id' => $this->user->getId(),
                'entity_namespace' => People::class,
                'entity_id' => $lead->people_id,
            ]
        );
        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $this->testApp->getId(), 'languages_id' => 1, 'verb' => 'text'],
            ['name' => 'Text']
        );
        $message = Message::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'message_types_id' => $messageType->getId(),
            'message' => ['content' => 'Yes, still interested', 'from_me' => false],
            'created_at' => $at,
            'updated_at' => $at,
        ]);
        $channel->addMessage($message);
    }

    private function ledger(Lead $lead, string $eventType): Event
    {
        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', $eventType)
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event, $eventType . ' was not recorded');

        return $event;
    }
}
