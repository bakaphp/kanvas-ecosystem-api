<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Actions\CreateAgentSwarmAction;
use Kanvas\Intelligence\Agents\DataTransferObject\AgentSwarm as AgentSwarmData;
use Kanvas\Intelligence\Agents\Enums\AgentSwarmStatusEnum;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentSwarmMember;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Tests\TestCase;

class PlanSwarmMilestoneTest extends TestCase
{
    public function testTerminalTransitionPostsToSwarmActivitiesChannel(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);

        $swarm = new CreateAgentSwarmAction(
            new AgentSwarmData(
                name: 'Milestone test swarm · ' . uniqid(),
                description: 'tmp',
                status: AgentSwarmStatusEnum::ACTIVE,
                config: null,
                app: $app,
                company: $company,
                user: $user,
            ),
        )->execute();

        AgentSwarmMember::create([
            'agent_swarm_id' => $swarm->getId(),
            'agent_id' => $agent->getId(),
            'role' => 'member',
            'is_deleted' => 0,
        ]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Milestone test plan',
                planType: 'workspace_issue',
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $swarmChannel = $swarm->socialChannels->first();
        $this->assertNotNull($swarmChannel);
        $beforeCount = $swarmChannel->messages()->count();

        new UpdatePlanAction(
            $plan,
            new PlanData(
                app: $app,
                company: $company,
                title: $plan->title,
                planType: $plan->plan_type,
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::DONE,
                output: ['summary' => 'Test plan finished cleanly.'],
            ),
        )->execute();

        $afterCount = $swarmChannel->fresh()->messages()->count();

        $this->assertSame($beforeCount + 1, $afterCount, 'Swarm channel should have one new milestone message');
    }

    public function testNonTerminalTransitionDoesNotPostToSwarm(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);

        $swarm = new CreateAgentSwarmAction(
            new AgentSwarmData(
                name: 'Non-terminal swarm · ' . uniqid(),
                description: 'tmp',
                status: AgentSwarmStatusEnum::ACTIVE,
                config: null,
                app: $app,
                company: $company,
                user: $user,
            ),
        )->execute();

        AgentSwarmMember::create([
            'agent_swarm_id' => $swarm->getId(),
            'agent_id' => $agent->getId(),
            'role' => 'member',
            'is_deleted' => 0,
        ]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Stays active',
                planType: 'workspace_issue',
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::DRAFT,
            ),
        )->execute();

        $swarmChannel = $swarm->socialChannels->first();
        $this->assertNotNull($swarmChannel);
        $beforeCount = $swarmChannel->messages()->count();

        new UpdatePlanAction(
            $plan,
            new PlanData(
                app: $app,
                company: $company,
                title: $plan->title,
                planType: $plan->plan_type,
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $afterCount = $swarmChannel->fresh()->messages()->count();

        $this->assertSame($beforeCount, $afterCount, 'Active is not terminal — no milestone post expected');
    }

    public function testTerminalTransitionWithNoSwarmIsSilentNoop(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['user_id' => $user->getId(), 'is_active' => true]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'No swarm',
                planType: 'workspace_issue',
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        // Just exercise the path — agent isn't in any swarm. Should not throw.
        $result = new UpdatePlanAction(
            $plan,
            new PlanData(
                app: $app,
                company: $company,
                title: $plan->title,
                planType: $plan->plan_type,
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::DONE,
            ),
        )->execute();

        $this->assertInstanceOf(Plan::class, $result);
        $this->assertSame('done', $result->status);
    }
}
