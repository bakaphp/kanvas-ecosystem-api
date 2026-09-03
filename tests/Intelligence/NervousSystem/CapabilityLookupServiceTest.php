<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Tavily\Enums\ConfigurationEnum as TavilyConfigurationEnum;
use Kanvas\Intelligence\Agents\Laravel\Tools\Tavily\TavilyWebResearchTool;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\CapabilityLookupService;
use Tests\TestCase;

/**
 * The catalog and the grants live on the `intelligence` connection, so it has to be listed for
 * transactions or the seeded tools survive the test and the next one matches them too.
 */
class CapabilityLookupServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_reports_not_found_when_nothing_in_the_catalog_matches(): void
    {
        $agent = $this->agent();

        $result = new CapabilityLookupService($agent)->lookup('zzqqxx unrelatedcapability');

        $this->assertTrue($result['not_found']);
        $this->assertSame([], $result['granted']);
        $this->assertSame([], $result['available']);
    }

    /** The refusal is the whole point — a bare "no match" reads as "try something else". */
    public function test_not_found_note_tells_the_model_not_to_substitute_a_similar_tool(): void
    {
        $agent = $this->agent();

        $result = new CapabilityLookupService($agent)->lookup('zzqqxx unrelatedcapability');

        $this->assertStringContainsString('Do NOT substitute', $result['note']);
    }

    public function test_lists_a_matching_tool_the_agent_was_not_granted_as_available(): void
    {
        $agent = $this->agent();
        $tool = $this->tool('zzqq_teleport_parcel', 'Teleports a zzqq parcel to a destination.');

        $result = new CapabilityLookupService($agent)->lookup('teleport parcel');

        $this->assertFalse($result['not_found']);
        $this->assertSame([], $result['granted']);
        $this->assertContains($tool->name, array_column($result['available'], 'name'));
    }

    public function test_lists_a_granted_tool_as_granted_not_available(): void
    {
        $agent = $this->agent();
        $tool = $this->tool('zzqq_teleport_parcel', 'Teleports a zzqq parcel to a destination.');
        $agent->selectedTools()->attach($tool->getKey());

        $result = new CapabilityLookupService($agent)->lookup('teleport parcel');

        $this->assertContains($tool->name, array_column($result['granted'], 'name'));
        $this->assertNotContains($tool->name, array_column($result['available'], 'name'));
    }

    /** A description mentions neighbouring concepts; a name is what the tool IS. */
    public function test_a_name_match_outranks_a_description_only_match(): void
    {
        $agent = $this->agent();
        $this->tool('zzqq_unrelated_thing', 'Mentions zzqqteleport only in passing prose here.');
        $named = $this->tool('zzqq_teleport_parcel', 'Moves a parcel.');

        $result = new CapabilityLookupService($agent)->lookup('zzqq_teleport');

        $this->assertSame($named->name, $result['available'][0]['name']);
    }

    public function test_an_empty_topic_asks_for_search_terms_instead_of_matching_everything(): void
    {
        $agent = $this->agent();

        $result = new CapabilityLookupService($agent)->lookup('  a of  ');

        $this->assertTrue($result['not_found']);
        $this->assertStringContainsString('Give me something to search for', $result['note']);
    }

    public function test_flags_a_connector_the_tenant_has_not_configured(): void
    {
        $agent = $this->agent();
        $app = app(Apps::class);
        $original = $app->get(TavilyConfigurationEnum::TAVILY_API_KEY->value);
        $app->set(TavilyConfigurationEnum::TAVILY_API_KEY->value, '');

        $this->tool(
            'zzqq_tavily_probe',
            'Runs a zzqq research probe.',
            handler: TavilyWebResearchTool::class,
        );

        try {
            $result = new CapabilityLookupService($agent)->lookup('zzqq_tavily_probe');
        } finally {
            $app->set(TavilyConfigurationEnum::TAVILY_API_KEY->value, $original);
        }

        $this->assertSame(['tavily'], array_column($result['needs_configuration'], 'slug'));
        $this->assertStringContainsString('is NOT set up for this company', $result['note']);
    }

    /** A configured connector must not be reported as a setup gap — that would send users chasing nothing. */
    public function test_does_not_flag_a_connector_that_is_configured(): void
    {
        $agent = $this->agent();
        $app = app(Apps::class);
        $original = $app->get(TavilyConfigurationEnum::TAVILY_API_KEY->value);
        $app->set(TavilyConfigurationEnum::TAVILY_API_KEY->value, 'tvly-test-key');

        $this->tool(
            'zzqq_tavily_probe',
            'Runs a zzqq research probe.',
            handler: TavilyWebResearchTool::class,
        );

        try {
            $result = new CapabilityLookupService($agent)->lookup('zzqq_tavily_probe');
        } finally {
            $app->set(TavilyConfigurationEnum::TAVILY_API_KEY->value, $original);
        }

        $this->assertSame([], $result['needs_configuration']);
    }

    /** Most of the catalog runs against our own database and has no connector to be unconfigured. */
    public function test_a_tool_with_no_connector_reports_no_configuration_gap(): void
    {
        $agent = $this->agent();
        $this->tool('zzqq_teleport_parcel', 'Teleports a zzqq parcel.');

        $result = new CapabilityLookupService($agent)->lookup('teleport parcel');

        $this->assertSame([], $result['needs_configuration']);
    }

    /**
     * Regression: a real PM turn asked to "send recurring nurturing emails every Monday", was shown
     * only send_* tools, concluded the platform could not schedule anything, and filed a capability
     * gap for `schedule_agent_task` — a tool it was already holding.
     *
     * Two things had to be true for that: "recurring" never literally appears in that tool's
     * description (it says "repeating" and "recurrence_cron"), and the send_* tools outscore it so far
     * that ranking alone buried it. Stemming fixes the first, per-term coverage the second.
     */
    public function test_a_loud_concept_does_not_bury_a_quiet_one(): void
    {
        $agent = $this->agent();

        $this->tool('zzqq_send_email', 'Send a zzqq email to a person right now.');
        $scheduler = $this->tool(
            'zzqq_schedule_task',
            'Schedule zzqq work for later. For a repeating task pass recurrence_cron and omit run_at.',
        );

        $result = new CapabilityLookupService($agent)->lookup('send recurring zzqq emails every monday');

        $names = array_merge(
            array_column($result['granted'], 'name'),
            array_column($result['available'], 'name'),
        );

        $this->assertContains($scheduler->name, $names);
    }

    /** "emails" must reach a tool whose text only ever says "email". */
    public function test_a_plural_term_matches_the_singular_in_a_description(): void
    {
        $agent = $this->agent();
        $tool = $this->tool('zzqq_mailer', 'Delivers a zzqq email to one recipient.');

        $result = new CapabilityLookupService($agent)->lookup('zzqq emails');

        $this->assertContains($tool->name, array_column($result['available'], 'name'));
    }

    private function agent(): Agent
    {
        $app = app(Apps::class);
        $user = static::$cachedUser;

        $type = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create(['agent_type_id' => $type->getId()]);
    }

    private function tool(string $name, string $description, ?string $handler = null): Tool
    {
        return Tool::create([
            'apps_id' => app(Apps::class)->getId(),
            'name' => $name,
            'description' => $description,
            'handler' => $handler,
            'tool_type' => 'system',
            'frameworks' => ['neuron'],
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);
    }
}
