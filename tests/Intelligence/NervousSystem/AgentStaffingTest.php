<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\GrantAgentToolsTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\NervousSystem\Capability\Services\ToolGrantResolver;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Staffing work the orchestrator cannot do itself.
 *
 * The behaviour under test is a reversal: hiring used to be bounded by the hiring agent's own
 * toolset, which meant a PM asked for a capability it lacked could only ever escalate to a human.
 * Real turn — the PM held `Schedule Agent Task` but not `Create Lead`, so "import these five people
 * and email them every Monday" ended in "grant me the capability or assign this to someone else".
 */
class AgentStaffingTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    /** The reversal itself: a tool nobody granted the caller is still grantable to a hire. */
    public function test_a_tool_the_caller_does_not_hold_can_still_be_granted(): void
    {
        $this->catalogTool('zzqq_import_records', ['neuron']);

        $resolved = $this->resolve('zzqq_import_records', 'neuron');

        $this->assertSame([], $resolved['refused']);
        $this->assertCount(1, $resolved['tools']);
    }

    /**
     * `Create Lead` genuinely exists twice — once for Laravel, once for Neuron. Picking the wrong row
     * is the worst available outcome because nothing reports it: `getActiveTools()` filters by
     * framework, so the agent starts up holding nothing and both ends look fine.
     */
    public function test_the_row_matching_the_agents_runtime_is_the_one_granted(): void
    {
        $this->catalogTool('zzqq_ambiguous', ['laravel']);
        $neuron = $this->catalogTool('zzqq_ambiguous', ['neuron']);

        $resolved = $this->resolve('zzqq_ambiguous', 'neuron');

        $this->assertSame([$neuron->getKey()], array_map(
            static fn (Tool $tool): int => (int) $tool->getKey(),
            $resolved['tools'],
        ));
    }

    /** Granting a tool the runtime cannot load is a silent no-op, so it fails here instead. */
    public function test_a_tool_for_another_runtime_is_refused_rather_than_silently_useless(): void
    {
        $this->catalogTool('zzqq_laravel_only', ['laravel']);

        $resolved = $this->resolve('zzqq_laravel_only', 'neuron');

        $this->assertSame([], $resolved['tools']);
        $this->assertStringContainsString('not for the neuron runtime', $resolved['refused']['zzqq_laravel_only']);
    }

    /**
     * The escalation boundary that replaced "only what the hirer holds". It has to be narrow enough to
     * let staffing through and hard enough that fan-out stays with a human.
     */
    public function test_the_tools_that_create_or_equip_agents_cannot_be_passed_on(): void
    {
        $resolved = $this->resolve('Hire Agent, Grant Agent Tools', 'neuron');

        $this->assertSame([], $resolved['tools']);
        $this->assertCount(2, $resolved['refused']);

        foreach ($resolved['refused'] as $reason) {
            $this->assertStringContainsString('stays with a human', $reason);
        }
    }

    public function test_an_unknown_tool_name_is_refused_with_where_to_look(): void
    {
        $resolved = $this->resolve('Not A Real Tool', 'neuron');

        $this->assertStringContainsString('capability_lookup', $resolved['refused']['Not A Real Tool']);
    }

    /** Self-granting is the one way an agent could widen its own boundary without review. */
    public function test_an_agent_cannot_grant_tools_to_itself(): void
    {
        $agent = $this->makeAgent();

        $result = new GrantAgentToolsTool($agent)
            ->withContext(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)
            ->forRequestingUser(static::$cachedUser)
            ->__invoke(agent_id: $agent->getId(), tools: 'Create Lead');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('cannot grant tools to yourself', $result['message']);
    }

    /** A hire belongs to no project yet, so ownership is the only route to finish equipping it. */
    public function test_a_hired_agent_can_be_equipped_by_the_agent_that_hired_it(): void
    {
        $tool = $this->catalogTool('zzqq_grantable', ['neuron']);
        $hirer = $this->makeAgent();
        $hire = $this->makeAgent();
        $hire->parent_id = $hirer->getId();
        $hire->saveQuietly();

        $result = new GrantAgentToolsTool($hirer)
            ->withContext(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)
            ->forRequestingUser(static::$cachedUser)
            ->__invoke(agent_id: $hire->getId(), tools: 'zzqq_grantable');

        $this->assertSame('success', $result['status']);
        $this->assertSame(['zzqq_grantable'], $result['granted']);
        $this->assertTrue($hire->selectedTools()->whereKey($tool->getKey())->exists());
    }

    /** Re-granting has to be safe: the PM re-checks a toolset far more often than it changes one. */
    public function test_granting_a_tool_the_agent_already_holds_changes_nothing(): void
    {
        $tool = $this->catalogTool('zzqq_already_held', ['neuron']);
        $hirer = $this->makeAgent();
        $hire = $this->makeAgent();
        $hire->parent_id = $hirer->getId();
        $hire->saveQuietly();
        $hire->selectedTools()->attach($tool->getKey());

        $result = $this->grant($hirer, $hire, 'zzqq_already_held');

        $this->assertSame('success', $result['status']);
        $this->assertSame([], $result['granted']);
        $this->assertSame(['zzqq_already_held'], $result['already_held']);
        $this->assertSame(1, $hire->selectedTools()->count());
    }

    /** An agent outside the caller's team is out of reach however the ids are supplied. */
    public function test_an_agent_on_no_shared_project_cannot_be_equipped(): void
    {
        $this->catalogTool('zzqq_stranger', ['neuron']);

        $result = $this->grant($this->makeAgent(), $this->makeAgent(), 'zzqq_stranger');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('not on any project you are on', $result['message']);
    }

    /**
     * @return array{tools: list<Tool>, refused: array<string, string>}
     */
    private function resolve(string $names, string $framework): array
    {
        return new ToolGrantResolver(app(Apps::class))->resolve($names, $framework);
    }

    /**
     * @return array<string, mixed>
     */
    private function grant(object $granter, object $target, string $tools): array
    {
        return new GrantAgentToolsTool($granter)
            ->withContext(app(Apps::class), static::$cachedUser->getCurrentCompany(), static::$cachedUser)
            ->forRequestingUser(static::$cachedUser)
            ->__invoke(agent_id: $target->getId(), tools: $tools);
    }

    /**
     * @param list<string> $frameworks
     */
    private function catalogTool(string $name, array $frameworks): Tool
    {
        return Tool::create([
            'apps_id' => app(Apps::class)->getId(),
            'name' => $name,
            'description' => 'Fixture tool.',
            'tool_type' => 'system',
            'frameworks' => $frameworks,
            'version' => '1.0.0',
            'is_active' => 1,
            'is_deleted' => 0,
        ]);
    }
}
