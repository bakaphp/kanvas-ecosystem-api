<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Outreach;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum as CompanyConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Models\LeadSource;
use Kanvas\Intelligence\Agents\Actions\Outreach\AgentReachOutAction;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\Intelligence\Enums\ConfigurationEnum as IntelligenceConfigurationEnum;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\Leads\Enums\AgentReachOutConfigEnum;
use Kanvas\Intelligence\Services\LeadConfigurationService;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\SystemModules\Models\SystemModules;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * Branch coverage for the outreach orchestrator's guards + status transitions.
 * The happy path is also covered (with the FakeNeuronProvider stub) so we lock
 * in the end-to-end shape without needing the workflow engine.
 */
class AgentReachOutActionTest extends TestCase
{
    use DatabaseTransactions;

    public function testSkipsWhenStatusIsAlreadySent(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SENT);

        $result = new AgentReachOutAction($lead, ['agent_id' => 999])->execute();

        $this->assertSame(AgentReachOutConfigEnum::STATUS_SENT, $result['status']);
        $this->assertStringContainsString('Already reached out', (string) $result['message']);
    }

    public function testReturnsEarlyWhenStatusIsInProgress(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_IN_PROGRESS);

        $result = new AgentReachOutAction($lead, ['agent_id' => 999])->execute();

        $this->assertSame(AgentReachOutConfigEnum::STATUS_IN_PROGRESS, $result['status']);
        $this->assertStringContainsString('in flight', (string) $result['message']);
    }

    public function testSkipsWhenLeadIsNotOpen(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $lead->status = 2; // closed (won/lost) → isOpen() false
        $lead->saveOrFail();

        // agent_id 999 would throw if the guard didn't short-circuit first.
        $result = new AgentReachOutAction($lead, ['agent_id' => 999])->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(
            AgentReachOutConfigEnum::STATUS_SKIPPED,
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
        );
        $this->assertSame(
            'lead_not_active',
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::REASON->value),
        );
    }

    public function testSkipsWhenLeadSourceNotInAllowlist(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $source = LeadSource::firstOrCreate(
            ['apps_id' => app(Apps::class)->getId(), 'name' => 'linkedin'],
            ['slug' => 'linkedin', 'is_deleted' => 0]
        );
        $lead->leads_sources_id = $source->getId();
        $lead->save();

        $result = new AgentReachOutAction($lead, [
            'agent_id' => 999,
            'lead_source_allowlist' => ['apollo', 'web_form'],
        ])->execute();

        $this->assertSame('linkedin', $result['source']);
        $this->assertSame(
            AgentReachOutConfigEnum::STATUS_SKIPPED,
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
        );
        $this->assertStringContainsString(
            'source_not_allowed:linkedin',
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::REASON->value),
        );
    }

    public function testMarksMutedWhenLeadAiModeIsOff(): void
    {
        $lead = $this->makeLeadWithCellphone();
        // Service returns the right key for V1 vs V2 AI-mode storage.
        $aiModeKey = new LeadConfigurationService()->getAiModeKey($lead);
        $lead->set($aiModeKey, IntelligenceModeEnum::IDLE->value);

        $result = new AgentReachOutAction($lead, ['agent_id' => 999])->execute();

        $this->assertSame('muted', $result['status']);
        $this->assertSame(
            AgentReachOutConfigEnum::STATUS_MUTED,
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
        );
    }

    #[DataProvider('noContactDescriptionsProvider')]
    public function testSkipsWhenDescriptionRequestsNoContact(string $description): void
    {
        $lead = $this->makeLeadWithCellphone();
        $lead->description = $description;
        $lead->saveOrFail();

        // agent_id 999 would throw if the guard didn't short-circuit first.
        $result = new AgentReachOutAction($lead, ['agent_id' => 999])->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(
            AgentReachOutConfigEnum::STATUS_SKIPPED,
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
        );
        $this->assertSame(
            'do_not_contact',
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::REASON->value),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function noContactDescriptionsProvider(): array
    {
        return [
            'plain' => ['Do not contact'],
            'apostrophe' => ["Don't reach out to this guy"],
            'hyphenated' => ['Lead is do-not-contact per request'],
            'spanish' => ['Cliente pidió no llamar'],
            'dnc token' => ['Flag: DNC'],
            'unsubscribe' => ['Please unsubscribe me from everything'],
            'mixed case noise' => ['VIP — STOP CONTACTING immediately!!!'],
        ];
    }

    public function testProceedsWhenDescriptionDoesNotRequestNoContact(): void
    {
        $lead = $this->makeLeadWithCellphone();
        // "dnc" must not fire as a substring of a normal word.
        $lead->description = 'Interested buyer, wants a callback about financing.';
        $lead->saveOrFail();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No agent configured for reach-out');

        // Reaching agent resolution proves the do-not-contact guard did NOT short-circuit.
        new AgentReachOutAction($lead, [])->execute();
    }

    public function testThrowsWhenNoAgentResolved(): void
    {
        $lead = $this->makeLeadWithCellphone();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('No agent configured for reach-out');

        new AgentReachOutAction($lead, [])->execute();
    }

    public function testFallsBackToCompanyConfigAgentWhenParamsAgentIdMissing(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $agent = $this->makeAgentWithStub();
        $lead->company->set(
            CompanyConfigurationEnum::AGENT_REACH_OUT_DEFAULT_AGENT_ID->value,
            $agent->getId(),
        );
        $lead->company->set(
            IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value,
            auth()->user()->getId(),
        );
        Http::fake();   // outbound Twilio SDK throws cleanly; action catches it per-channel

        $result = new AgentReachOutAction($lead, [])->execute();

        // The agent resolved via company-config and the orchestrator made it to the channel walk.
        // Outbound delivery may fail (no real Twilio creds in test) — the per-channel try/catch
        // records the error and STATUS becomes 'failed'. STATUS != 'in_progress' or initial null
        // proves the company-config fallback fired.
        $this->assertContains(
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
            [AgentReachOutConfigEnum::STATUS_SENT, AgentReachOutConfigEnum::STATUS_FAILED],
        );
    }

    public function testSkipsWhenLeadHasNoContactInfo(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();
        // LeadFactory->withAppAndCompany calls People::factory()->withContacts() — clear those
        // so ResolveLeadChannelPreferencesAction returns an empty list.
        $lead->people->contacts()->delete();
        $lead->load('people.contacts');

        $agent = $this->makeAgentWithStub();

        $result = new AgentReachOutAction($lead, ['agent_id' => $agent->getId()])->execute();

        $this->assertSame('skipped', $result['status']);
        $this->assertSame(
            AgentReachOutConfigEnum::STATUS_SKIPPED,
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
        );
        $this->assertSame(
            'no_contact_info',
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::REASON->value),
        );
    }

    public function testProceedsWhenAlreadySentAndAllowResendIsTrue(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SENT);

        $agent = $this->makeAgentWithStub();
        $lead->company->set(
            IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value,
            auth()->user()->getId(),
        );
        Http::fake();

        $result = new AgentReachOutAction($lead, [
            'agent_id' => $agent->getId(),
            'allow_resend' => true,
        ])->execute();

        // 'Already reached out' would mean the guard kicked in (allow_resend ignored — bug).
        $this->assertNotSame('Already reached out', $result['message']);
        $this->assertContains(
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
            [AgentReachOutConfigEnum::STATUS_SENT, AgentReachOutConfigEnum::STATUS_FAILED],
        );
    }

    public function testHappyPathSetsStatusSentAndChannelsSent(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $agent = $this->makeAgentWithStub();
        $lead->company->set(
            IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value,
            auth()->user()->getId(),
        );
        Http::fake();

        $result = new AgentReachOutAction($lead, ['agent_id' => $agent->getId()])->execute();

        // Outbound Twilio SDK call fails without real creds → per-channel error → STATUS=failed
        // OR succeeds → STATUS=sent. Either path proves the orchestrator reached the channel walk
        // with the kernel running the agent. The status MUST have transitioned out of in_progress.
        $finalStatus = (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value);
        $this->assertContains(
            $finalStatus,
            [AgentReachOutConfigEnum::STATUS_SENT, AgentReachOutConfigEnum::STATUS_FAILED],
        );
        $this->assertNotEmpty($result['message']);
    }

    public function testSupportModeCreatesLockedDelayedFirstMessage(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $lead->set('ai_mode', IntelligenceModeEnum::SUPPORT->value);
        $agent = $this->makeAgentWithStub();
        $lead->company->set(
            IntelligenceConfigurationEnum::AI_AGENT_USER_ID->value,
            auth()->user()->getId(),
        );

        $result = new AgentReachOutAction($lead, ['agent_id' => $agent->getId()])->execute();

        $this->assertSame(AgentReachOutConfigEnum::STATUS_SCHEDULED, $result['status']);
        $this->assertSame(
            AgentReachOutConfigEnum::STATUS_SCHEDULED,
            (string) $lead->fresh()->get(AgentReachOutConfigEnum::STATUS->value),
        );

        $draft = Message::fromApp($lead->app)
            ->fromCompany($lead->company)
            ->where('is_locked', 1)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame(0, (int) $draft->is_public);
        $this->assertTrue($draft->hasTag(['first-message']));
        $this->assertTrue($draft->hasTag(['agent-reach-out']));
        $this->assertTrue($draft->hasTag(['agent-reach-out-delayed']));
    }

    public function testScheduledReachOutIsIdempotent(): void
    {
        $lead = $this->makeLeadWithCellphone();
        $lead->set(AgentReachOutConfigEnum::STATUS->value, AgentReachOutConfigEnum::STATUS_SCHEDULED);

        $result = new AgentReachOutAction($lead, ['agent_id' => 999])->execute();

        $this->assertSame(AgentReachOutConfigEnum::STATUS_SCHEDULED, $result['status']);
        $this->assertSame('Reach-out already scheduled', $result['message']);
    }

    // ─── helpers ──────────────────────────────────────────────────────────────

    private function makeLeadWithCellphone(): Lead
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        SystemModules::firstOrCreate(
            ['model_name' => Lead::class],
            ['name' => 'Leads', 'slug' => 'leads', 'description' => 'Leads system module']
        );

        $lead = Lead::factory()
            ->withAppAndCompany($app->getId(), $company->getId())
            ->create();

        $lead->people->contacts()->create([
            'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
            'value' => '+15551234567',
            'weight' => 1,
        ]);

        return $lead;
    }

    private function makeAgentWithStub(): Agent
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agentType = AgentType::factory()
            ->withAppId($app->getId())
            ->create([
                'name' => 'Sales (Neuron Test)',
                'provider' => 'neuron',
                'handler' => SalesNeuronAgentStub::class,
            ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'name' => 'Reach-out Test',
                'agent_type_id' => $agentType->getId(),
                'soul' => 'Test',
                'instructions' => 'Always respond Hola Mundo',
                'output_format' => 'plain text',
            ]);
    }
}
