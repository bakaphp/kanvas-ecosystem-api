<?php

declare(strict_types=1);

namespace Tests\Intelligence\Tools;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\CompanyConfiguration\AppScopedHireAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\HireAgentTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use Mockery;
use NeuronAI\Tools\ToolPropertyInterface;
use Tests\TestCase;

final class AppScopedHireAgentToolTest extends TestCase
{
    private ?Companies $company = null;

    public function testCreatesASubAgentWithALocalCallableTool(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);
        $result = $this->tool($hirer)->__invoke(
            name: 'Subagent ' . fake()->unique()->uuid(),
            role: 'Research assistant',
            instructions: 'Answer the parent request using the supplied context.',
            is_sub_agent: true,
        );

        $this->assertTrue($result['hired'], $result['message'] ?? '');
        $child = Agent::findOrFail($result['agent_id']);
        $this->assertTrue((bool) $child->is_sub_agent);
        $this->assertSame($hirer->getId(), (int) $child->parent_id);
        $this->assertSame($this->company()->getId(), (int) $child->companies_id);
        $this->assertNotSame((int) $hirer->user_id, (int) $child->user_id);
        $callable = Tool::findOrFail($result['sub_agent_tool_id']);
        $this->assertSame($child->getId(), (int) $callable->agents_id);
        $this->assertSame($this->kanvasApp()->getId(), (int) $callable->apps_id);
        $this->assertFalse($hirer->selectedTools()->whereKey($callable->getId())->exists());
        $this->assertStringContainsString('sub-agent', $result['message']);
    }

    public function testRefusesASubAgentWithAParentFromAnotherCompany(): void
    {
        $hirer = $this->hiringAgent([]);
        $hirer->companies_id = $this->company()->getId() + 100000;
        $result = $this->tool($hirer)->__invoke(
            name: 'Invalid subagent ' . fake()->uuid(),
            role: 'Worker',
            instructions: 'Do the requested work.',
            is_sub_agent: true,
        );
        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('same app and company', $result['message']);
    }

    public function testAParentIdWithoutTheSubAgentFlagIsRefusedBeforeAnythingIsHired(): void
    {
        $hirer = $this->hiringAgent([]);
        $before = Agent::query()->count();
        $result = $this->tool($hirer)->__invoke(
            name: 'Orphan ' . fake()->uuid(),
            role: 'Worker',
            instructions: 'Do the requested work.',
            parent_agent_id: $hirer->getId(),
        );
        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('is_sub_agent=true', $result['message']);
        $this->assertSame($before, Agent::query()->count());
    }

    public function testOmittingSubAgentFlagStillCreatesAnIndependentAgent(): void
    {
        $hirer = $this->hiringAgent([]);
        $result = $this->tool($hirer)->__invoke(
            name: 'Independent ' . fake()->uuid(),
            role: 'Worker',
            instructions: 'Do the requested work.',
        );
        $this->assertTrue($result['hired'], $result['message'] ?? '');
        $this->assertFalse($result['is_sub_agent']);
        $this->assertNull($result['sub_agent_tool_id']);
        $this->assertFalse(Tool::where('agents_id', $result['agent_id'])->exists());
    }

    public function testOnlyTheAppScopedToolExposesTheSubAgentAndCompanyParameters(): void
    {
        $names = fn (object $tool): array => array_map(
            fn (ToolPropertyInterface $property): string => $property->getName(),
            $tool->getProperties(),
        );

        $stable = $names(new HireAgentTool());
        $scoped = $names(new AppScopedHireAgentTool());

        foreach (['is_sub_agent', 'parent_agent_id', 'company_uuid'] as $parameter) {
            $this->assertNotContains($parameter, $stable);
            $this->assertContains($parameter, $scoped);
        }
    }

    private function hiringAgent(array $toolNames): Agent
    {
        $agent = Agent::factory()
            ->withAppId($this->kanvasApp()->getId())
            ->withCompanyId($this->company()->getId())
            ->create([
                'user_id' => $this->currentUser()->getId(),
                'name' => 'Hirer ' . fake()->unique()->lexify('?????'),
                'is_active' => true,
            ]);

        $agent->selectedTools()->sync(Tool::whereIn('name', $toolNames)->pluck('id')->all());

        return $agent;
    }

    private function tool(Agent $hirer): AppScopedHireAgentTool
    {
        // Keep this fixture independent of the shared local user's role assignments.
        $admin = Mockery::mock($this->currentUser());
        $admin->shouldReceive('isAdmin')->andReturn(true);

        return new AppScopedHireAgentTool($hirer)
            ->withContext($this->kanvasApp(), $this->company(), $this->currentUser())
            ->forRequestingUser($admin);
    }

    private function kanvasApp(): Apps
    {
        return app(Apps::class);
    }

    /**
     * A company of this test's own: hiring is capped per company and the shared one fills up on a
     * long-lived database, so the cap message would replace every assertion here.
     */
    private function company(): Companies
    {
        return $this->company ??= Companies::factory()->create([
            'users_id' => $this->currentUser()->getId(),
        ]);
    }

    private function currentUser(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
