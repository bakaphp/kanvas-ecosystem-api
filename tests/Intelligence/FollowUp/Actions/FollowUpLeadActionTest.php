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
        // Cold lead, no inbound history → silence should render as
        // "no prior inbound on file", never the raw PHP_INT_MAX value.
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
        $this->assertStringContainsString('no prior inbound', $prompt);
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
        $this->assertSame('agent-composed body in template style', $outcome->message);
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

    public function testFollowUpKernelCallOptsOutOfThreadScopingForCrossSessionHistory(): void
    {
        // Regression: previously the kernel called setThreadId for the follow-up
        // path (because sourceChannel was null), which made the agent's history
        // loader filter to a single session's messages. The fix is to pass
        // sourceChannel: $session->channel so the kernel skips setThreadId.
        // After the fix, the agent sees the full cross-session conversation
        // history for the People entity — the same rollup pattern channel
        // responders use.
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

        // setThreadId was NOT called → the kernel hit the rollup branch
        // (sourceChannel was non-null) → history loader sees cross-session msgs.
        $this->assertFalse(
            FollowUpAgentStub::$setThreadIdWasCalled,
            'FollowUpLeadAction must pass sourceChannel to AgentChatKernel so it skips setThreadId. Without this the agent only sees one session\'s messages and produces literal-template-copy bland follow-ups (regression KANVAS-ECOSYSTEM-5RF).'
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
        $this->assertContains(
            'follow-up body the operator must see',
            $messageBodies,
            "Lead channel must contain the actual outbound body so operators don't have to query agent_histories."
        );
    }

    public function testProviderErrorMarkerSurfacedInOutcomeReasonViaFallback(): void
    {
        // Force the provider to throw so RunNeuronChatAction's catch block
        // fires and returns its `[provider_error: ...]`-tagged fallback.
        // The follow-up action's invalid-JSON catch should bubble that marker
        // into the outcome.reason so debugging needs ONE Sentry event, not two.
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
        // The provider error class and message reached the follow-up outcome.
        $this->assertStringContainsString('[provider_error: RuntimeException', (string) $outcome->reason);
        $this->assertStringContainsString('Simulated Gemini timeout', (string) $outcome->reason);

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
}
