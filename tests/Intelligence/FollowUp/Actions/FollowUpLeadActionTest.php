<?php

declare(strict_types=1);

namespace Tests\Intelligence\FollowUp\Actions;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum as LeadConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\AgentEnum;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\FollowUp\Actions\FollowUpLeadAction;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpOutcomeKindEnum;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Social\MessagesTypes\Models\MessageType;
use Kanvas\SystemModules\Models\SystemModules;
use Tests\Stubs\FollowUp\FollowUpAgentStub;
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

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    private Apps $testApp;

    private \Kanvas\Companies\Models\Companies $company;

    private \Kanvas\Users\Models\Users $user;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
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
    public function testSkipsWhenChannelNotConfigured(): void
    {
        // Stage config only enables 'email' but session is on 'whatsapp'.
        $cfg = $this->defaultStageConfig();
        $cfg['follow_up']['channels'] = [
            ['type' => 'email', 'enabled' => true, 'template_name' => null],
        ];
        $lead = $this->seedLeadWithStageConfig($cfg);
        $this->seedSessionAndChannel($lead, 'whatsapp');

        $outcome = new FollowUpLeadAction(
            app: $this->testApp,
            company: $this->company,
            lead: $lead,
            agent: $this->seedFollowUpAgent(),
        )->execute();

        $this->assertSame(FollowUpOutcomeKindEnum::SKIPPED, $outcome->kind);
        $this->assertSame('channel_not_configured', $outcome->reason);
    }

    // ─────────────────────────────────────────────────────────────────────
    // Gate 6: silence below interval AND force=false → too_soon
    // ─────────────────────────────────────────────────────────────────────
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

        $this->assertSame(FollowUpOutcomeKindEnum::SENT, $outcome->kind);
        $this->assertSame('Hello Maria, just checking in.', $outcome->message);

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

    // ─────────────────────────────────────────────────────────────────────
    // Gate 8 — agent says SKIP → exhaust(agent_gave_up)
    // ─────────────────────────────────────────────────────────────────────
    public function testExhaustsWhenAgentDeclinesToRespond(): void
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

        $this->assertSame(FollowUpOutcomeKindEnum::EXHAUSTED, $outcome->kind);
        $this->assertStringContainsString('agent: person_disengaged', (string) $outcome->reason);

        $lead->refresh();
        $this->assertTrue($lead->isFollowUpExhausted());
        $this->assertStringStartsWith('agent:', (string) $lead->getFollowUpExhaustedReason());
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
                'role' => ['background' => '', 'steps' => '', 'output' => ''],
            ]);
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

        return Lead::factory()
            ->withAppAndCompany($this->testApp->getId(), $this->company->getId())
            ->create([
                'pipeline_id' => $pipeline->getId(),
                'pipeline_stage_id' => $stage->getId(),
            ]);
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
}
