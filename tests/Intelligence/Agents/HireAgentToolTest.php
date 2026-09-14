<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\HireAgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\NervousSystem\UpdateAgentInstructionsTool;
use Kanvas\NervousSystem\Capability\Models\Tool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class HireAgentToolTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'intelligence'];

    private ?Companies $company = null;

    public function testHiresATeammateWithItsOwnIdentity(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window', 'Create Message']);
        $name = 'Newsroom ' . fake()->unique()->lexify('?????');

        $result = $this->tool($hirer, $this->currentUser())->__invoke(
            name: $name,
            role: 'Newsroom writer',
            instructions: 'Read the channel. Write an article only when something is newsworthy; '
                . 'otherwise do nothing.',
            tools: 'Read Channel Window, Create Message',
        );

        $this->assertTrue($result['hired'], $result['message'] ?? '');

        /** @var Agent $hired */
        $hired = Agent::query()->whereKey($result['agent_id'])->first();

        $this->assertNotNull($hired);
        $this->assertSame($name, $hired->name);
        $this->assertFalse((bool) $hired->is_sub_agent, 'A hire is a teammate, never a sub-agent.');
        $this->assertSame(
            ['Create Message', 'Read Channel Window'],
            $hired->selectedTools()->pluck('name')->sort()->values()->all()
        );
    }

    /**
     * Agents sharing a user share an identity, and every guard that asks "did this agent produce this
     * record?" starts answering yes for work it never did.
     */
    public function testTheHireGetsItsOwnUserNotTheHirers(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);

        $result = $this->tool($hirer, $this->currentUser())->__invoke(
            name: 'Distinct ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: 'Do the thing, or nothing when there is nothing to do.',
        );

        $this->assertTrue($result['hired'], $result['message'] ?? '');

        $hired = Agent::query()->whereKey($result['agent_id'])->first();

        $this->assertNotSame((int) $hirer->user_id, (int) $hired->user_id);
        $this->assertNotSame($this->currentUser()->getId(), (int) $hired->user_id);
    }

    /**
     * The reversal. This used to assert the opposite — a hire could hold nothing its hirer did not
     * already hold — on the reasoning that otherwise an agent launders a capability it was denied.
     * Nothing in this system denies a tool, though; it only ever grants one, so all the guard achieved
     * was to stop an orchestrator staffing work it could not do itself, which is most of the job. A
     * real turn ended with the PM asking a human to grant it `Create Lead` rather than hiring someone
     * who had it.
     */
    public function testItCanGrantAToolItDoesNotHoldItself(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);

        $result = $this->tool($hirer, $this->currentUser())->__invoke(
            name: 'Staffed ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: 'Create the leads you are given. Do nothing when there are none.',
            tools: 'Create Lead',
        );

        $this->assertTrue($result['hired'], $result['message'] ?? '');
        $this->assertSame(['Create Lead'], $result['tools']);
    }

    /** What replaced it, and the reason fan-out stays bounded without the toolset bounding it. */
    public function testItCannotPassOnTheToolsThatCreateOrEquipAgents(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);
        $before = Agent::query()->count();

        $result = $this->tool($hirer, $this->currentUser())->__invoke(
            name: 'Escalated ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: 'Do the thing.',
            tools: 'Hire Agent',
        );

        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('stays with a human', $result['message']);
        $this->assertArrayHasKey('refused', $result);
        $this->assertSame($before, Agent::query()->count(), 'Nothing may be created on a refused grant.');
    }

    /**
     * A fresh hire belongs to no project, so a project-only rule would leave the agent that created it
     * unable to correct it — the correction loop broken for exactly the agents that most need it.
     */
    public function testAHirerCanRetuneWhatItHired(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);

        $result = $this->tool($hirer, $this->currentUser())->__invoke(
            name: 'Retunable ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: 'Write things up. Do nothing when there is nothing worth writing.',
        );

        $this->assertTrue($result['hired'], $result['message'] ?? '');

        $hired = Agent::query()->whereKey($result['agent_id'])->first();

        $this->assertSame($hirer->getId(), (int) $hired->parent_id, 'The hiring link must be recorded.');
        $this->assertFalse((bool) $hired->is_sub_agent, 'Lineage must not turn the hire into a sub-agent.');

        $retune = new UpdateAgentInstructionsTool($hirer)
            ->withContext($this->kanvasApp(), $this->company(), $this->currentUser())
            ->__invoke(
                agent_id: $hired->getId(),
                reason: 'Too noisy.',
                instructions: 'Only cover product launches.',
            );

        $this->assertSame('success', $retune['status'], $retune['message'] ?? '');
        $this->assertSame('Only cover product launches.', $hired->refresh()->instructions);
    }

    /**
     * A partial failure leaves the agent's user behind, and its address is derived rather than
     * chosen — so without reuse, every retry after any failure is impossible without manual cleanup.
     */
    public function testARetryAfterAPartialFailureReusesTheOrphanedUser(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);
        $name = 'Reused ' . fake()->unique()->lexify('?????');

        $first = $this->tool($hirer, $this->currentUser())->__invoke(
            name: $name,
            role: 'Worker',
            instructions: 'Do the thing, or nothing.',
        );

        $this->assertTrue($first['hired'], $first['message'] ?? '');

        $hired = Agent::query()->whereKey($first['agent_id'])->first();
        $orphanedUserId = (int) $hired->user_id;

        // Simulate the agent creation having failed after the user was provisioned.
        $hired->forceDelete();

        $second = $this->tool($hirer, $this->currentUser())->__invoke(
            name: $name,
            role: 'Worker',
            instructions: 'Do the thing, or nothing.',
        );

        $this->assertTrue($second['hired'], $second['message'] ?? '');
        $this->assertSame(
            $orphanedUserId,
            (int) Agent::query()->whereKey($second['agent_id'])->first()->user_id,
            'The retry must reuse the user the failed attempt left behind.'
        );
    }

    public function testANonAdminCannotHire(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);
        $before = Agent::query()->count();

        $result = $this->tool($hirer, Users::factory()->create())->__invoke(
            name: 'Blocked ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: 'Do the thing.',
        );

        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('administrator', $result['message']);
        $this->assertSame($before, Agent::query()->count());
    }

    public function testInstructionsAreRequiredBecauseToolsWithoutAJobDoNothing(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);

        $result = $this->tool($hirer, $this->currentUser())->__invoke(
            name: 'Jobless ' . fake()->unique()->lexify('?????'),
            role: 'Worker',
            instructions: '   ',
        );

        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('instructions', $result['message']);
    }

    public function testHiringADuplicateNameIsRefused(): void
    {
        $hirer = $this->hiringAgent(['Read Channel Window']);

        $result = $this->tool($hirer, $this->currentUser())->__invoke(
            name: $hirer->name,
            role: 'Worker',
            instructions: 'Do the thing, or nothing.',
        );

        $this->assertFalse($result['hired']);
        $this->assertStringContainsString('already has an agent', $result['message']);
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

    private function tool(Agent $hirer, Users $requestingUser): HireAgentTool
    {
        return new HireAgentTool($hirer)
            ->withContext($this->kanvasApp(), $this->company(), $this->currentUser())
            ->forRequestingUser($requestingUser);
    }

    private function kanvasApp(): Apps
    {
        return app(Apps::class);
    }

    /**
     * A company of this test's own, never the shared one every other test hires into.
     *
     * Hiring is capped per company, and on a long-lived CI database that shared company accumulates
     * agents across the whole suite — it was at 75 when this first broke. Every assertion here then
     * reads back the cap message instead of the behaviour under test, so the tests fail for a reason
     * that has nothing to do with them.
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
