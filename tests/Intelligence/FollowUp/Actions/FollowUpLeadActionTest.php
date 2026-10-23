<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event as EventFacade;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Services\CrmLeadSourceSynchronizer;
use Kanvas\Guild\Leads\Services\SmsOptOutNoticeService;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\DataTransferObject\FollowUpOutcome;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpOutcomeKindEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpValueEnum;
use Kanvas\Intelligence\Knowledge\Events\KnowledgeIndexRequested;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\Intelligence\Sessions\Services\SessionChannelService;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Notifications\Templates\Blank;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\Models\WorkflowAction;
use Kanvas\Workflow\Rules\Enums\ActionKindEnum;
use Kanvas\Workflow\Rules\Models\Rule;
use Kanvas\Workflow\Rules\Models\RuleAction;
use Kanvas\Workflow\Rules\Models\RuleCondition;
use Kanvas\Workflow\Rules\Models\RuleType;
use Kanvas\Workflow\Rules\Models\RuleWorkflowAction;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use ReflectionProperty;
use RuntimeException;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
use Tests\Stubs\FollowUp\RecordingAfterFollowUpActivity;
use Tests\TestCase;

/**
 * E2E coverage for FollowUpLeadAction — every gate, every outcome.
 *
 * Real DB, real models, real CreateMessageAction. Outbound HTTP is faked
 * (Http::fake) so SendMessageToLeadAction's call to WaSender/Twilio/Mailgun
 * never touches a real API. The agent is stubbed via FollowUpAgentStub so
 * the Gemini provider returns a canned JSON string per test.
 *
 * Setup helpers are split by gate cost — tests that exit at gates 1-2 skip
 * the heavyweight Session/Channel/SystemModules wiring.
 */
class FollowUpLeadActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social', 'workflow'];

    private Apps $testApp;

    private \Kanvas\Companies\Models\Companies $company;

    private \Kanvas\Users\Models\Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        EventFacade::fake([KnowledgeIndexRequested::class]);
        FollowUpAgentStub::reset();

        $this->testApp = app(Apps::class);
        /** @var \Kanvas\Users\Models\Users $authedUser */
        $authedUser = auth()->user();
        $this->user = $authedUser;
        $this->company = $this->user->getCurrentCompany();

        // The kernel + persistence path need an AI agent user resolved off
        // the company via getAiAgentUserOrFail.
        $this->company->set(IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value, $this->user->getId());

        // Register Lead in system_modules so CreateSocialMessageAction can
        // resolve the polymorphic entity link.
        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 1: config absent → follow_up_disabled
    // ─────────────────────────────────────────────────────────────────────
    public function testSkipsWhenStageHasNoFollowUpConfig(): void
    {
        $lead = $this->seedLeadWithStageConfig(null);
        $agent = $this->seedFollowUpAgent();

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $agent,
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('follow_up_disabled', $outcome->reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 1a: closed lead (leads_status_id outside the open set) → lead_not_active (skip)
    // A won/lost lead must not be nudged even in a non-terminal nudge stage.
    // ─────────────────────────────────────────────────────────────────────
    public function testSkipsWhenLeadIsNotOpen(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $lead->close(); // leads_status_id outside the open set → hasOpenLeadStatus() false

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('lead_not_active', $outcome->reason);
        $this->assertFalse($lead->isFollowUpExhausted(), 'Closed-lead skip is not an exhaustion.');
    }

    // Force does NOT bypass the active-lead gate — it only relaxes too_soon.
    public function testForceDoesNotBypassActiveLeadGate(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $lead->close();

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
            force: true,
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('lead_not_active', $outcome->reason);
    }

    // Company settings are shared by every paratest process, so the start date must not leak.
    #[Group('serial')]
    public function testSkipsLeadCreatedBeforeCompanyFollowUpStartDateEvenWhenForced(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());

        $outcome = $this->runWithFollowUpStartDate(
            Carbon::now()->subDay()->toDateString(),
            $lead,
            force: true,
        );

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('lead_created_before_follow_up_start_date', $outcome->reason);
        $this->assertFalse($lead->isFollowUpExhausted());
    }

    #[Group('serial')]
    public function testLeadCreatedAfterCompanyFollowUpStartDatePassesTheGate(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());

        $outcome = $this->runWithFollowUpStartDate(
            Carbon::now()->subDays(60)->toDateString(),
            $lead,
        );

        $this->assertNotSame('lead_created_before_follow_up_start_date', $outcome->reason);
    }

    #[Group('serial')]
    public function testMissingOrInvalidFollowUpStartDateFollowsUpEveryLead(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());

        foreach ([null, 'not-a-date'] as $value) {
            $outcome = $this->runWithFollowUpStartDate($value, $lead);

            $this->assertNotSame('lead_created_before_follow_up_start_date', $outcome->reason);
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 1b: already exhausted → exhausted (skipped path)
    // ─────────────────────────────────────────────────────────────────────
    public function testSkipsWhenLeadAlreadyExhausted(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $lead->markFollowUpExhausted('agent: prior_test');
        $agent = $this->seedFollowUpAgent();

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $agent,
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('exhausted', $outcome->reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 1c: count >= max_retries → exhausted(max_retries) + emit ledger event
    // ─────────────────────────────────────────────────────────────────────
    public function testMaxRetriesAdvancesWithoutExhaustingNextStage(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['max_retries'] = 1;
        $cfg['follow_up']['exhausted_action'] = 'advance';
        [$lead, $stage1, $stage2] = $this->seedLeadWithTwoStages($cfg);
        $stage2->update(['config' => $cfg]);
        $lead->set('follow_up_state', ['count' => 1, 'last_at' => now()->subDay()->toIso8601String()]);

        $outcome = new FollowUpLeadAction(
            $this->testApp,
            $this->company,
            $lead,
            $this->seedFollowUpAgent()
        )->execute();

        $lead->refresh();
        $this->assertEquals($stage2->getId(), $lead->pipeline_stage_id);
        $this->assertFalse($lead->isFollowUpExhausted());
        $this->assertNull($lead->getFollowUpExhaustedReason());
        $this->assertSame(0, $lead->getFollowUpStateCount());
        $this->assertNotNull($lead->getFollowUpStageEnteredAt());
        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('max_retries_stage_advanced', $outcome->reason);
        $this->assertNull($outcome->message);
        $this->assertSame('too_soon', (new \Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService())->dispatchSkipReason($lead));
        $this->assertFalse(Event::query()->where('apps_id', $this->testApp->getId())
            ->where('source_entity_id', $lead->getId())
            ->where('event_type', 'lead.follow_up.exhausted')->exists());
    }

    public function testMaxRetriesExhaustsWhenAdvanceHasNoNextStage(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['max_retries'] = 1;
        $cfg['follow_up']['exhausted_action'] = 'advance';
        $lead = $this->seedLeadWithStageConfig($cfg);
        $stageId = $lead->pipeline_stage_id;
        $lead->set('follow_up_state', ['count' => 1]);

        $outcome = new FollowUpLeadAction(
            $this->testApp,
            $this->company,
            $lead,
            $this->seedFollowUpAgent()
        )->execute();

        $lead->refresh();
        $this->assertEquals($stageId, $lead->pipeline_stage_id);
        $this->assertTrue($lead->isFollowUpExhausted());
        $this->assertSame('max_retries', $lead->getFollowUpExhaustedReason());
        $this->assertSame(FollowUpOutcomeKindEnum::EXHAUSTED, $outcome->kind);
        $this->assertSame('max_retries', $outcome->reason);
    }

    public function testExhaustsOnMaxRetriesAndEmitsLedgerEvent(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['max_retries'] = 2;
        $lead = $this->seedLeadWithStageConfig($cfg);

        // Force count to the max.
        $lead->set('follow_up_state', ['count' => 2, 'channels_used' => []]);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::EXHAUSTED, $outcome->kind);
        $this->assertSame('max_retries', $outcome->reason);

        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', 'lead.follow_up.exhausted')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('max_retries', $event->payload['reason'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 2: handed-off lead → exhausted(handed_off) — TERMINAL
    // Once routed to a human, the automated flow is done for good.
    // ─────────────────────────────────────────────────────────────────────
    public function testExhaustsWhenLeadHasBeenHandedOff(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $lead->set(IntelligenceConfigurationEnum::AGENT_HAND_OFF->value, true);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::EXHAUSTED, $outcome->kind);
        $this->assertSame('handed_off', $outcome->reason);

        $lead->refresh();
        $this->assertTrue($lead->isFollowUpExhausted(), 'Handoff is terminal — lead must be exhausted.');
        $this->assertSame('handed_off', $lead->getFollowUpExhaustedReason());

        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', 'lead.follow_up.exhausted')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('handed_off', $event->payload['reason'] ?? null);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 2: AI mode manual (human takeover) → ai_mode_manual (skip)
    // ─────────────────────────────────────────────────────────────────────
    public function testSkipsWhenLeadAiModeIsManual(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $lead->set(LeadConfigurationEnum::AI_MODE_IS_MANUAL->value, true);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('ai_mode_manual', $outcome->reason);
        $this->assertFalse($lead->isFollowUpExhausted(), 'Manual mode is pausable, not exhausting.');
    }

    public function testMissingAiFollowUpFieldAllowsFollowUp(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $this->assertNull($lead->get(IntelligenceModeEnum::AI_FOLLOW_UP->value));

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame('no_session', $outcome->reason, 'A lead never touched by the triggers must pass the ai_follow_up gate.');
    }

    public function testSkipsWhenAiFollowUpIsPresentButNotEnabled(): void
    {
        foreach ([FollowUpValueEnum::OFF()->value, FollowUpTypeEnum::NO_FOLLOW_UP->value] as $stored) {
            $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
            $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, $stored);

            $outcome = new FollowUpLeadAction(
                app: $this->testApp,
                company: $this->company,
                lead: $lead,
                agent: $this->seedFollowUpAgent(),
                force: true,
            )->execute();

            $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind, (string) $stored);
            $this->assertSame('lead_follow_up_disabled', $outcome->reason, (string) $stored);
        }
    }

    public function testEnabledAiFollowUpPassesTheGate(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpValueEnum::ON()->value);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame('no_session', $outcome->reason);
    }

    public function testSkipsWhenAiModeIsIdleOrOff(): void
    {
        foreach ([IntelligenceModeEnum::IDLE, IntelligenceModeEnum::OFF] as $mode) {
            $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
            $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, $mode->value);

            $outcome = new FollowUpLeadAction(
                app: $this->testApp,
                company: $this->company,
                lead: $lead,
                agent: $this->seedFollowUpAgent(),
                force: true,
            )->execute();

            $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind, $mode->value);
            $this->assertSame('ai_mode_off', $outcome->reason, $mode->value);
            $this->assertFalse($lead->isFollowUpExhausted(), 'Muted AI is pausable, not exhausting.');
        }
    }

    public function testActiveAiModePassesTheMuteGate(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $lead->set(IntelligenceConfigurationEnum::AI_MODE->value, IntelligenceModeEnum::FULL_ON->value);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame('no_session', $outcome->reason, 'FULL_ON must reach the gates after the mute check.');
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 3: no session → no_session
    // ─────────────────────────────────────────────────────────────────────
    public function testSkipsWhenNoSessionExists(): void
    {
        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        // Deliberately NO session created.

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('no_session', $outcome->reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 4: session channel not in stage config → channel_not_configured
    // ─────────────────────────────────────────────────────────────────────
    public function testSkipsWhenLeadHasNoReachableContactForEnabledChannels(): void
    {
        // Stage enables ONLY whatsapp. Person has only an email contact (no
        // phones). Resolver returns zero candidates → no_reachable_channel.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'whatsapp', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'whatsapp');

        // Strip all phone contacts so the person can only be emailed.
        $lead->people->contacts()->whereIn('contacts_types_id', [2, 3, 8])->delete();

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('no_reachable_channel', $outcome->reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 6: silence below interval AND force=false → too_soon
    // ─────────────────────────────────────────────────────────────────────
    public function testStageChangeRestartsIntervalBeforeCallingAgent(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['time_based']['interval_minutes'] = 4320;
        [$lead, , $nextStage] = $this->seedLeadWithTwoStages($cfg);
        $nextStage->config = $cfg;
        $nextStage->saveOrFail();
        $this->seedSessionAndChannel($lead, 'sms');
        $lead->bumpFollowUp(['sms'], null);
        $lead->pipeline_stage_id = $nextStage->id;
        $lead->saveOrFail();
        $lead->refresh();

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertNull($lead->getFollowUpLastAt());
        $this->assertNotNull($lead->getFollowUpStageEnteredAt());
        $this->assertSame('too_soon', $outcome->reason);
        $this->assertSame(0, $lead->getFollowUpStateCount());
    }

    public function testSkipsWhenSilenceBelowInterval(): void
    {
        // Default stage config enables sms — match the session channel so the
        // gate-4 check passes and gate-5 (too_soon) actually fires.
        $cfg = $this->defaultStageConfig();
        $lead = $this->seedLeadWithStageConfig($cfg);
        $lead->set('follow_up_state', [
            'count' => 1,
            'last_at' => Carbon::now()->subMinute()->toIso8601String(),
            'channels_used' => ['sms'],
        ]);
        $this->seedSessionAndChannel($lead, 'sms');

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('too_soon', $outcome->reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 7: WhatsApp + outside 24h window + no template → outside_24h_no_template
    // ─────────────────────────────────────────────────────────────────────
    public function testSkipsWhenWhatsAppOutside24hWithoutTemplate(): void
    {
        $cfg = $this->defaultStageConfig();
        // Channel enabled but template = null → outside-24h must skip.
        $cfg['follow_up']['channels'] = [
            ['type' => 'whatsapp', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'whatsapp');

        // No inbound message → getLastInboundAt returns null → treated as outside 24h.

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('outside_24h_no_template', $outcome->reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 8 — agent says SEND (happy path)
    // ─────────────────────────────────────────────────────────────────────
    public function testSendsWhenAgentSaysShouldRespond(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            // sms doesn't have the 24h restriction — simpler happy path.
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hello Maria, just checking in.',
            reason: 'polite_check_in',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');
        // First SMS ever to this person: the opt-out clause rides along, as on every other SMS path.
        $this->assertSame(
            "Hello Maria, just checking in.\n\n" . SmsOptOutNoticeService::NOTICE,
            $outcome->message
        );
        $this->assertTrue(
            FollowUpAgentStub::$lastPrivateUserTurn,
            'The follow-up prompt must run as a private turn so it never lands in memory as a user request.',
        );

        $lead->refresh();
        $this->assertSame(1, $lead->getFollowUpStateCount());
        $this->assertContains('sms', $lead->getFollowUpChannelsUsed());

        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', 'lead.follow_up.sent')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
    }

    // The test company has no Twilio sender route, which SendMessageToLeadAction reports as
    // `success: false` instead of throwing — the shape every non-retryable provider rejection takes.
    public function testProviderRejectionLeavesASendFailedLedgerRecord(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hello Maria, just checking in.',
            reason: 'polite_check_in',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', 'lead.follow_up.send_failed')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('send_failed', $event->payload['reason']);
        $this->assertSame('sms', $event->payload['channel']);
        $this->assertStringContainsString('sender route is missing', $event->payload['error']);
    }

    public function testSmsFollowUpOmitsOptOutNoticeWhenTheSmsChannelAlreadyHasAnOutbound(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');
        $smsChannel = $this->seedSmsChannelWithPriorOutbound($lead);

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hello Maria, still interested?',
            reason: 'polite_check_in',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');
        $this->assertSame('Hello Maria, still interested?', $outcome->message);
        $this->assertSame(1, $this->countMessagesOn($smsChannel, 'Hello Maria, still interested?'), 'The follow-up must land on the existing SMS channel.');
    }

    public function testFollowUpLandsOnTheChannelItWasSentThroughNotTheSessionChannel(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $emailSession = $this->seedSessionAndChannel($lead, 'email');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hello Maria, still interested?',
            reason: 'polite_check_in',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');

        $cellphone = (string) $lead->people->getCellPhones()->first()->value;
        $smsChannel = Channel::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('slug', SessionChannelService::createChannelSlug('sms', $cellphone))
            ->first();
        $this->assertNotNull($smsChannel, 'A first SMS opens the SMS channel.');
        $this->assertSame(People::class, $smsChannel->entity_namespace);
        $this->assertSame((int) $lead->people_id, (int) $smsChannel->entity_id);
        $this->assertSame(1, $this->countMessagesOn($smsChannel, (string) $outcome->message));
        $this->assertSame(0, $this->countMessagesOn($emailSession->channel, (string) $outcome->message));
    }

    public function testNonSmsFollowUpNeverCarriesTheSmsOptOutNotice(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'email');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hola Maria, seguimos en contacto.',
            reason: 'polite_check_in',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');
        $this->assertSame('Hola Maria, seguimos en contacto.', $outcome->message);
    }

    public function testFiresAfterFollowUpWorkflowOnTheLeadOnceSent(): void
    {
        RecordingAfterFollowUpActivity::reset();
        $this->seedAfterFollowUpRule(RecordingAfterFollowUpActivity::class);

        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hello Maria, just checking in.',
            reason: 'polite_check_in',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');
        $this->assertCount(1, RecordingAfterFollowUpActivity::$calls, 'CRM hook must fire exactly once per sent touch.');

        ['entity_id' => $entityId, 'params' => $params] = RecordingAfterFollowUpActivity::$calls[0];
        $this->assertSame($lead->getId(), $entityId);
        $this->assertSame(WorkflowEnum::AFTER_FOLLOW_UP->value, $params['currentEventTypeName']);
        $this->assertSame($outcome->message, $params['body']);
        $this->assertSame(['sms'], $params['channels']);
        $this->assertFalse($params['advanced_stage']);
        $this->assertInstanceOf(Message::class, $params['message']);
        $this->assertSame($lead->pipeline_stage_id, $params['stage_id']);
    }

    public function testDoesNotFireAfterFollowUpWorkflowWhenNothingWasSent(): void
    {
        RecordingAfterFollowUpActivity::reset();
        $this->seedAfterFollowUpRule(RecordingAfterFollowUpActivity::class);

        $lead = $this->seedLeadWithStageConfig($this->defaultStageConfig());
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: false,
            advanceStage: false,
            message: null,
            reason: 'customer_asked_for_space',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame([], RecordingAfterFollowUpActivity::$calls);
    }

    /**
     * CRM calls are billed per request, so the pull must sit behind every free gate: a lead that is
     * too soon to nudge never reaches the synchronizer.
     */
    public function testTooSoonLeadNeverCostsACrmCall(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');
        $lead->bumpFollowUp(['sms'], null);

        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldNotReceive('canSync');
        $synchronizer->shouldNotReceive('sync');

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
            synchronizer: $synchronizer,
        )->execute();

        $this->assertSame('too_soon', $outcome->reason);
    }

    public function testFailedCrmSyncSkipsBeforeTheAgentRuns(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');
        FollowUpAgentStub::configure(shouldRespond: true, message: 'should never be composed');

        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(true);
        $synchronizer->shouldReceive('sync')->once()->andThrow(new RuntimeException('CRM unreachable'));

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
            synchronizer: $synchronizer,
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('crm_sync_failed', $outcome->reason);
        $this->assertSame([], FollowUpAgentStub::$lastReceivedMessages, 'No agent turn on an unverified lead.');
    }

    public function testLeadClosedByTheCrmPullIsNotNudged(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');
        FollowUpAgentStub::configure(shouldRespond: true, message: 'should never be composed');

        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(true);
        // The real pull writes leads_status_id through the connector; stand in for that write.
        $synchronizer->shouldReceive('sync')->once()->andReturnUsing(fn (Lead $l) => $l->close());

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
            synchronizer: $synchronizer,
        )->execute();

        $this->assertSame('lead_not_active', $outcome->reason, 'The gates a pull can flip must run again on its result.');
        $this->assertSame([], FollowUpAgentStub::$lastReceivedMessages);
    }

    public function testSyncedOpenLeadProceedsToTheAgent(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');
        FollowUpAgentStub::configure(shouldRespond: true, message: 'Hello Maria, just checking in.', reason: 'ok');

        $synchronizer = Mockery::mock(CrmLeadSourceSynchronizer::class);
        $synchronizer->shouldReceive('canSync')->once()->andReturn(true);
        $synchronizer->shouldReceive('sync')->once();

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
            synchronizer: $synchronizer,
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind, $outcome->reason ?? '');
    }

    private function countMessagesOn(Channel $channel, string $content): int
    {
        return Message::query()
            ->where('message->content', $content)
            ->whereHas('channels', fn ($q) => $q->where('channels.id', $channel->getId()))
            ->count();
    }

    private function seedSmsChannelWithPriorOutbound(Lead $lead): Channel
    {
        $cellphone = (string) $lead->people->getCellPhones()->first()->value;

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $this->testApp->getId(),
                'companies_id' => $this->company->getId(),
                'slug' => SessionChannelService::createChannelSlug('sms', $cellphone),
            ],
            ['name' => 'SMS ' . $cellphone, 'description' => 'test', 'users_id' => $this->user->getId()]
        );

        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $this->testApp->getId(), 'languages_id' => 1, 'verb' => 'twilio-sms'],
            ['name' => 'Twilio SMS']
        );
        $priorOutbound = Message::factory()
            ->withAppId($this->testApp->getId())
            ->withCompanyId($this->company->getId())
            ->withMessageType($messageType)
            ->create([
                'message' => ['content' => 'earlier text. Reply STOP to opt out.', 'from_me' => true, 'from_ia' => true],
                'is_locked' => 0,
                'is_un_response' => 0,
                'is_deleted' => 0,
            ]);
        $channel->messages()->attach($priorOutbound->getId(), ['users_id' => $this->user->getId()]);

        return $channel;
    }

    /**
     * A company-scoped sync rule on the Lead module so the activity runs inline inside the test
     * instead of being queued.
     */
    private function seedAfterFollowUpRule(string $activityClass): void
    {
        $ruleType = RuleType::firstOrCreate(['name' => WorkflowEnum::AFTER_FOLLOW_UP->value]);
        $leadModuleId = SystemModulesRepository::getByModelName(Lead::class, $this->testApp)->getId();

        $rule = Rule::factory()->withAsync(false)->create([
            'systems_modules_id' => $leadModuleId,
            'companies_id' => $this->company->getId(),
            'apps_id' => $this->testApp->getId(),
            'rules_types_id' => $ruleType->getId(),
            'name' => 'After follow-up CRM hook',
            'description' => 'test',
            'params' => [],
        ]);
        RuleCondition::factory()->create([
            'rules_id' => $rule->getId(),
            'attribute_name' => 'id',
            'operator' => '>=',
            'value' => 0,
        ]);

        $action = WorkflowAction::factory()->create([
            'name' => class_basename($activityClass),
            'model_name' => $activityClass,
            'kind' => ActionKindEnum::WORKFLOW->value,
        ]);
        $workflowAction = RuleWorkflowAction::factory()->create([
            'actions_id' => $action->getId(),
            'system_modules_id' => $leadModuleId,
        ]);
        RuleAction::factory()->create([
            'rules_id' => $rule->getId(),
            'rules_workflow_actions_id' => $workflowAction->getId(),
            'weight' => 0,
        ]);
    }

    // Gate 8 — agent says SKIP. Treated as transient SKIP (NOT terminal
    // exhaust) so the next cron tick re-evaluates. The agent never
    // permanently kills a lead — only max_retries / handed_off / operator do.
    public function testSkipsWhenAgentDeclinesToRespond(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: false,
            advanceStage: false,
            message: null,
            reason: 'person_disengaged',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertStringContainsString('agent: person_disengaged', (string) $outcome->reason);

        $lead->refresh();
        // Critical: lead is NOT exhausted — retries next cron tick.
        $this->assertFalse($lead->isFollowUpExhausted());
        $this->assertNull($lead->getFollowUpExhaustedReason());
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 8 — agent says ADVANCE STAGE → pipeline_stage_id changes
    // ─────────────────────────────────────────────────────────────────────
    public function testAdvancesStageWhenAgentRequests(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];

        // Need two stages so advancement has a target.
        [$lead, $stage1, $stage2] = $this->seedLeadWithTwoStages($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: false,
            advanceStage: true,
            message: null,
            reason: 'goal_met',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
        $lead->refresh();
        $this->assertSame($stage2->getId(), $lead->pipeline_stage_id);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Force=true bypasses the too_soon gate (manual mutation path)
    // ─────────────────────────────────────────────────────────────────────
    public function testForceBypassesTooSoonGate(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $lead->set('follow_up_state', [
            'count' => 0,
            'last_at' => Carbon::now()->subMinute()->toIso8601String(),
            'channels_used' => [],
        ]);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'forced nudge',
            reason: 'forced',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
            force: true,
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
    }

    // ═════════════════════════════════════════════════════════════════════
    // Fixture helpers
    // ═════════════════════════════════════════════════════════════════════

    private function defaultStageConfig(): array
    {
        return [
            'follow_up' => [
                'enabled' => true,
                'mode' => 'time_based',
                'time_based' => ['interval_minutes' => 1440, 'advance_after_max_retries' => false],
                'goal_based' => null,
                'max_retries' => 5,
                'exhausted_action' => 'stop',
                'agent_name' => null,
                'channels' => [
                    ['type' => 'sms', 'enabled' => true, 'template_name' => null],
                ],
                'channel_selection' => 'sticky_then_priority',
                'respect_work_hours' => true,
                'respect_lead_opt_outs' => true,
                'write_system_message_on_stage_change' => true,
            ],
            'stage_meta' => ['is_terminal' => false],
        ];
    }

    private function seedFollowUpAgent(): Agent
    {
        $agentType = AgentType::factory()
            ->withAppId($this->testApp->getId())
            ->create([
                'provider' => 'neuron',
                'handler' => FollowUpAgentStub::class,
            ]);

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

    private function runWithFollowUpStartDate(?string $startDate, Lead $lead, bool $force = false): FollowUpOutcome
    {
        $key = IntelligenceConfigurationEnum::LEAD_FOLLOW_UP_START_DATE->value;
        $startDate === null ? $this->company->del($key) : $this->company->set($key, $startDate);

        try {
            return new FollowUpLeadAction(
                app: $this->testApp,
                company: $this->company,
                lead: $lead,
                agent: $this->seedFollowUpAgent(),
                force: $force,
            )->execute();
        } finally {
            $this->company->del($key);
        }
    }

    private function seedLeadWithStageConfig(?array $stageConfig): Lead
    {
        $pipeline = Pipeline::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'system_modules_id' => 0,
            'name' => 'Test Pipeline',

            'is_default' => 0,
        ]);

        $stage = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Stage A',
            'weight' => 1,
            'config' => $stageConfig,
        ]);

        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create([
                'pipeline_id' => $pipeline->getId(),
                'pipeline_stage_id' => $stage->getId(),
                'created_at' => Carbon::now()->subDays(30),
            ]);

        return $lead;
    }

    /**
     * @return array{0: Lead, 1: PipelineStage, 2: PipelineStage}
     */
    private function seedLeadWithTwoStages(array $stageConfig): array
    {
        $pipeline = Pipeline::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'system_modules_id' => 0,
            'name' => 'Test Pipeline',

            'is_default' => 0,
        ]);

        $stage1 = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Stage A',
            'weight' => 1,
            'config' => $stageConfig,
        ]);

        $stage2 = PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => 'Stage B',
            'weight' => 2,
            'config' => null,
        ]);

        $lead = Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create([
                'pipeline_id' => $pipeline->getId(),
                'pipeline_stage_id' => $stage1->getId(),
                'created_at' => Carbon::now()->subDays(30),
            ]);

        return [$lead, $stage1, $stage2];
    }

    private function seedSessionAndChannel(Lead $lead, string $channelSlug): Session
    {
        $messageType = MessageType::firstOrCreate(
            ['apps_id' => $this->testApp->getId(), 'languages_id' => 1, 'verb' => $channelSlug],
            ['name' => ucfirst($channelSlug)]
        );

        $channel = Channel::firstOrCreate(
            [
                'apps_id' => $this->testApp->getId(),
                'companies_id' => $this->company->getId(),
                'slug' => "test-{$channelSlug}-" . $lead->getId(),
            ],
            ['name' => 'Test ' . ucfirst($channelSlug), 'description' => 'test', 'users_id' => $this->user->getId()]
        );

        // Session::getChannel() derives the channel type from a marker
        // embedded in the UUID — wa-chat for whatsapp, twilio for sms,
        // email for email. Match that convention so the channel resolves.
        $marker = match ($channelSlug) {
            'whatsapp' => 'wa-chat',
            'email' => 'email',
            default => 'twilio',
        };

        return Session::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'channel_id' => $channel->getId(),
            'entity_namespace' => People::class,
            'entity_id' => $lead->people_id,
            'uuid' => $marker . '-' . (string) \Illuminate\Support\Str::uuid(),
            'user' => [],
            'content' => [],
            'is_deleted' => 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Channel resolution — multi-channel selection via the resolver path.
    // Regression coverage for the bug where Session::getChannel() drove the
    // outbound choice and skipped leads whose latest session was on a
    // non-configured channel even when other channels were reachable.
    // ─────────────────────────────────────────────────────────────────────

    public function testSessionFromOtherChannelStillProvidesMemoryWhileResolverPicksConfiguredChannel(): void
    {
        // Stage enables ONLY email. Session is on whatsapp (memory continuity).
        // Old code skipped with channel_not_configured; new code resolves
        // email from the person's contacts and proceeds.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'whatsapp');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hi via email despite whatsapp memory.',
            reason: 'happy_path_multi_channel',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
        $lead->refresh();
        $this->assertContains('email', $lead->getFollowUpChannelsUsed());
    }

    public function testStickyThenPriorityIsAliasedToPriorityOnlyInV1(): void
    {
        // In v1, sticky_then_priority is aliased to priority_only because the
        // previous session-UUID-marker sticky signal was unreliable. With
        // config [email, sms] and a session on sms, the action picks email
        // (config[0]) — not sms. v1.5 will implement true sticky via
        // last-inbound-message lookup; see FollowUp CLAUDE.md.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $cfg['follow_up']['channel_selection'] = 'sticky_then_priority';
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Priority wins (sticky aliased in v1).',
            reason: 'sticky_v1_alias',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
        $lead->refresh();
        $this->assertContains('email', $lead->getFollowUpChannelsUsed());
        $this->assertNotContains('sms', $lead->getFollowUpChannelsUsed());
    }

    public function testPriorityOnlyHonorsConfigOrderRegardlessOfStickyChannel(): void
    {
        // Stage enables [email, sms]. Last inbound is on sms. priority_only
        // ignores the sticky signal and picks email (config[0]).
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $cfg['follow_up']['channel_selection'] = 'priority_only';
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Priority wins over sticky.',
            reason: 'priority_only',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
        $lead->refresh();
        $this->assertContains('email', $lead->getFollowUpChannelsUsed());
        $this->assertNotContains('sms', $lead->getFollowUpChannelsUsed());
    }

    public function testStickyConfigSendsCleanlyOnSingleEnabledChannel(): void
    {
        // Sanity check: sticky_then_priority config keyword doesn't break the
        // single-channel happy path even when the session is on a different
        // channel. (v1 aliases sticky to priority_only, so config[0] wins.)
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $cfg['follow_up']['channel_selection'] = 'sticky_then_priority';
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'whatsapp');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Sends cleanly.',
            reason: 'sticky_alias',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
    }

    public function testRespectsOptOutOnContact(): void
    {
        // Person's email contact is opted out + no phones eligible →
        // no_reachable_channel skip.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'email');

        // Wipe phones, opt out the email.
        $lead->people->contacts()->whereIn('contacts_types_id', [2, 3, 8])->delete();
        $lead->people->contacts()->where('contacts_types_id', 1)->update(['is_opt_out' => 1]);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('no_reachable_channel', $outcome->reason);
    }

    public function testFanOutAllDispatchesToEveryReachableChannelOnSingleTouch(): void
    {
        // Stage enables [email, sms]. Person has both contacts. fan_out_all
        // should dispatch the same body to BOTH channels and bump count by 1.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $cfg['follow_up']['channel_selection'] = 'fan_out_all';
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Cross-channel ping.',
            reason: 'fan_out',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);

        $lead->refresh();
        // One touch — count bumps by 1, not 2.
        $this->assertSame(1, $lead->getFollowUpStateCount());
        // Both channels recorded in follow_up_state.channels_used.
        $this->assertContains('email', $lead->getFollowUpChannelsUsed());
        $this->assertContains('sms', $lead->getFollowUpChannelsUsed());

        // Ledger event lists both channels in payload.
        $event = Event::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('event_type', 'lead.follow_up.sent')
            ->where('source_entity_id', $lead->getId())
            ->latest('id')
            ->first();
        $this->assertNotNull($event);
        $this->assertSame('fan_out_all', $event->payload['channel_selection_strategy']);
        $this->assertContains('email', $event->payload['channels']);
        $this->assertContains('sms', $event->payload['channels']);
    }

    public function testColdLeadPromptDoesNotLeakPhpIntMax(): void
    {
        // Cold leads use creation time when there is no activity or stage clock.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Hi.',
            reason: 'cold_lead',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $prompt = FollowUpAgentStub::lastPromptText();
        $this->assertStringNotContainsString('9223372036854775807', $prompt);
        $this->assertStringContainsString('creation time if none', $prompt);
    }

    public function testPromptIncludesRenderedTemplateAsToneReferenceWhenTemplateConfigured(): void
    {
        // Template body contains Blade placeholders + an agent_message slot.
        // Prompt should show the rendered body with placeholders resolved
        // and the slot replaced with [agent message slot] sentinel.
        $template = \Kanvas\Templates\Models\Templates::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => 'fup_style_v1',
            'title' => 'Following up with {{ $lead->company->name }}',
            'template' => 'Hey {{ $people?->firstname ?? \'there\' }}, {{ $agent_message }} — Sales',
        ]);

        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => $template->name],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'circling back on pricing',
            reason: 'style_ref',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $prompt = FollowUpAgentStub::lastPromptText();
        $this->assertStringContainsString('Tone & voice reference', $prompt);
        $this->assertStringContainsString('Hey ', $prompt);
        $this->assertStringContainsString('[agent message slot]', $prompt);
        $this->assertStringContainsString('— Sales', $prompt);
        // Anti-pattern guidance reaches the LLM.
        $this->assertStringContainsString('Do NOT use generic phrases', $prompt);
        // Positive direction: leverage conversation history concretely.
        $this->assertStringContainsString('reiterate that offer CONCRETELY', $prompt);
    }

    public function testStaticTemplateDispatchesAgentMessageDirectlyAndAgentMessageSlotTemplateWraps(): void
    {
        // STATIC template (no $agent_message placeholder) → outbound dispatches
        // agent message directly; template was a style guide only.
        $staticTemplate = \Kanvas\Templates\Models\Templates::create([
            'apps_id' => $this->testApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->user->getId(),
            'name' => 'fup_static_v1',
            'title' => 'Static template',
            'template' => 'Hi {{ $lead->people->firstname ?? \'there\' }}, static body.',
        ]);

        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => $staticTemplate->name],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'agent-composed body in template style',
            reason: 'static_template',
        );

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        // Agent's message is what gets sent, NOT the rendered static template.
        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
        $this->assertStringStartsWith('agent-composed body in template style', (string) $outcome->message);
    }

    public function testLastInboundIsFoundOnDifferentChannelThanPickedSession(): void
    {
        // Person has an inbound on channel A (email) from 30 min ago, but the
        // picked session is on channel B (sms). With interval_minutes = 60,
        // the lead-scoped query finds the email inbound and skips with too_soon.
        // The old session-scoped code would have missed it.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['mode'] = 'time_based';
        $cfg['follow_up']['time_based'] = ['interval_minutes' => 60, 'advance_after_max_retries' => false];
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $smsSession = $this->seedSessionAndChannel($lead, 'sms');

        // Create an inbound message on a DIFFERENT channel (email) for the same person.
        $emailChannel = Channel::firstOrCreate(
            [
                'apps_id' => $this->testApp->getId(),
                'companies_id' => $this->company->getId(),
                'slug' => 'recent-inbound-email-' . $lead->getId(),
            ],
            [
                'name' => 'Email channel',
                'description' => 'test',
                'users_id' => $this->user->getId(),
                'entity_namespace' => \Kanvas\Guild\Customers\Models\People::class,
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
            'message' => ['content' => 'hi', 'from_me' => false],
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        $emailChannel->addMessage($message);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        // 30 min of silence < 60 min interval → too_soon.
        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('too_soon', $outcome->reason);
    }

    // Any touch restarts the interval, not just the customer's inbound: an agent reply or
    // reach-out 30 min ago means the lead was just talked to.
    public function testRecentOutboundMessageRestartsTheInterval(): void
    {
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['time_based'] = ['interval_minutes' => 60, 'advance_after_max_retries' => false];
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        $personChannel = Channel::firstOrCreate(
            [
                'apps_id' => $this->testApp->getId(),
                'companies_id' => $this->company->getId(),
                'slug' => 'recent-outbound-' . $lead->getId(),
            ],
            [
                'name' => 'Person channel',
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
            'message' => ['content' => 'agent reply', 'from_me' => true, 'from_ia' => true],
            'created_at' => now()->subMinutes(30),
            'updated_at' => now()->subMinutes(30),
        ]);
        $personChannel->addMessage($message);

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('too_soon', $outcome->reason);
    }

    public function testPromptIncludesDefaultStyleReferenceWhenNoTemplateConfigured(): void
    {
        // Stage has no template_name on the channel config. Prompt should
        // still include a "Tone reference" section with neutral default copy
        // — the agent must NEVER see "No template configured" with no anchor,
        // which was provoking the LLM to bail with canned fallback text.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'email');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'check-in body',
            reason: 'default_ref',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $prompt = FollowUpAgentStub::lastPromptText();

        // The default reference is always rendered when no template is configured.
        $this->assertStringContainsString('Tone & voice reference', $prompt);
        $this->assertStringContainsString('neutral default tone reference', $prompt);
        $this->assertStringNotContainsString('No template configured for this stage.', $prompt);
        // Email default has "Best," sign-off in the body.
        $this->assertStringContainsString('Best,', $prompt);
    }

    public function testPromptIncludesCurrentTimeWithTimezoneSoLlmCanReasonAboutDates(): void
    {
        // Bug: agent reading "ping me Sunday" in conversation history had no
        // anchor for "today's date" so it assumed Sunday was upcoming. By
        // then it was actually Monday and the customer was overdue. Fix:
        // include the current time + timezone so the LLM can interpret
        // relative date references from prior messages.
        Carbon::setTestNow(Carbon::create(2026, 6, 8, 14, 32, 0, 'America/New_York'));
        // $company->timezone reads the model attribute (column on companies table),
        // not the custom-fields ->set('timezone', ...) value. Update directly.
        $this->company->update(['timezone' => 'America/New_York']);

        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'whatever',
            reason: 'time_anchor',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $prompt = FollowUpAgentStub::lastPromptText();

        // Concrete date + day-of-week + timezone in the prompt.
        $this->assertStringContainsString('Current time: Monday', $prompt);
        $this->assertStringContainsString('June 8, 2026', $prompt);
        $this->assertStringContainsString('America/New_York', $prompt);
        // The instruction telling the LLM to use it for time-relative references.
        $this->assertStringContainsString('Use this to interpret time-relative references', $prompt);
        // Explicit nudge that prior relative dates may be in the past.
        $this->assertStringContainsString('may now be in the PAST', $prompt);

        Carbon::setTestNow();
    }

    public function testFollowUpKernelCallOptsOutOfThreadScopingForCrossSessionHistory(): void
    {
        // Regression KANVAS-ECOSYSTEM-5RF: threaded by the cron's own session, the agent saw only that
        // session's messages. With sourceChannel the kernel threads by the session entity (the lead's
        // person) and the rollup store loads the whole cross-session history, as channel responders do.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'whatever',
            reason: 'cross_session_rollup',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(
            $lead->people->uuid,
            FollowUpAgentStub::$lastThreadId,
            'FollowUpLeadAction must pass sourceChannel to AgentChatKernel so the thread is the session entity (the lead\'s person), not the cron session. Otherwise the agent only sees one session\'s messages and produces literal-template-copy bland follow-ups (regression KANVAS-ECOSYSTEM-5RF).'
        );
    }

    public function testSentMessageIsAttachedToLeadDefaultChannelForUiVisibility(): void
    {
        // The action persists the outbound body to the session's channel AND
        // to the Lead's default channel (via LeadChannelService) so the
        // customer-facing thread shows what went out, not just the agent's
        // JSON in agent_histories. Without this attach, operators have to
        // guess what was sent.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'follow-up body the operator must see',
            reason: 'lead_channel_visibility',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        // LeadChannelService creates/uses the Lead's default channel. Find it.
        $leadChannel = Channel::query()
            ->where('apps_id', $this->testApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('entity_namespace', Lead::class)
            ->where('entity_id', $lead->getId())
            ->latest('id')
            ->first();

        $this->assertNotNull($leadChannel, 'Lead default channel must exist after the send.');

        $messageBodies = $leadChannel->messages()
            ->get()
            ->pluck('message.content')
            ->filter()
            ->all();
        // First SMS to this person, so the stored body carries the opt-out clause too.
        $this->assertContains(
            "follow-up body the operator must see\n\n" . SmsOptOutNoticeService::NOTICE,
            $messageBodies,
            "Lead channel must contain the actual outbound body so operators don't have to query agent_histories."
        );
    }

    public function testProviderErrorFallbackReachesOutcomeReasonWithoutLeakingRawException(): void
    {
        // Force the provider to throw so RunNeuronChatAction's catch block fires and returns its
        // humanized fallback. The follow-up action's invalid-JSON catch bubbles that fallback into
        // outcome.reason — but the raw exception class/message must never appear in it, since that
        // string is also what a live-chat channel would show a real user.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        FollowUpAgentStub::$throwOnChat = new \RuntimeException('Simulated Gemini timeout');

        try {
            $outcome = new FollowUpLeadAction(
                app: $this->testApp,
                company: $this->company,
                lead: $lead,
                agent: $this->seedFollowUpAgent(),
            )->execute();
        } finally {
            FollowUpAgentStub::reset();
        }

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertStringStartsWith('agent_call_failed: ', (string) $outcome->reason);
        $this->assertStringContainsString('I ran into a hiccup processing that', (string) $outcome->reason);
        $this->assertStringNotContainsString('RuntimeException', (string) $outcome->reason);
        $this->assertStringNotContainsString('Simulated Gemini timeout', (string) $outcome->reason);

        // Lead is NOT exhausted — retries next tick.
        $lead->refresh();
        $this->assertFalse($lead->isFollowUpExhausted());
    }

    public function testInvalidJsonAgentResponseSkipsWithRawOutputInReason(): void
    {
        // Agent returns non-JSON. AgentFollowUpResult::fromKernelResponse now
        // throws a RuntimeException with the raw output truncated to 2000 chars.
        // The action's try/catch reports + skips with 'agent_call_failed: ...'
        // so the lead retries next tick (was: terminal exhaust on invalid JSON).
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'sms', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'sms');

        // The stub ships the cannedResponse string verbatim. Configure it to be
        // explanatory prose instead of JSON.
        FollowUpAgentStub::$cannedResponse = "Sorry, I can't help with that request right now.";

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertStringStartsWith('agent_call_failed: ', (string) $outcome->reason);
        $this->assertStringContainsString("Sorry, I can't help", (string) $outcome->reason);

        // Lead is NOT exhausted — it can retry next cron tick.
        $lead->refresh();
        $this->assertFalse($lead->isFollowUpExhausted());
    }

    public function testEmailFollowUpReusesOriginalSubjectAsReplyThread(): void
    {
        // The outreach anchored the original subject on the lead. The follow-up
        // must reuse it (prefixed "Re: ") so the email threads under the
        // original conversation instead of opening a brand-new thread.
        Notification::fake();

        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'email');
        $lead->set('title_email_follow_up', 'Skydropx x Kanvas — operations');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Following up on my last note.',
            reason: 'thread_reuse',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification): bool {
                $subject = new ReflectionProperty($notification, 'subject')->getValue($notification);

                return $subject === 'Re: Skydropx x Kanvas — operations';
            }
        );
    }

    public function testEmailFollowUpFallsBackToCompanyNameWhenNoAnchoredSubject(): void
    {
        // Legacy / non-email-originated lead with no anchored subject → fall back
        // to the company name (NOT "Re: <company>").
        Notification::fake();

        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'email');

        FollowUpAgentStub::configure(
            shouldRespond: true,
            advanceStage: false,
            message: 'Just checking in.',
            reason: 'no_anchor_fallback',
        );

        new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $companyName = $this->company->name;
        Notification::assertSentOnDemand(
            Blank::class,
            function (Blank $notification) use ($companyName): bool {
                $subject = new ReflectionProperty($notification, 'subject')->getValue($notification);

                return $subject === $companyName;
            }
        );
    }
}
