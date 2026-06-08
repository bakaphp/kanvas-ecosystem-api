<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\AddTaskAction;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\DataTransferObject\Task as TaskData;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Tests\TestCase;

/**
 * The agent does the work, so ledger events for an agent-assigned Plan/Task must be attributed to
 * the Agent (ACTOR_TYPE=Agent, ACTOR_ID=agent_id) even though a human owns/created them — that's
 * what the nervous-system "agent activity" query filters on. Only with no agent do we fall back
 * to the human owner.
 */
final class AgentActorAttributionTest extends TestCase
{
    public function testAgentAssignedPlanEmitsAgentActoredEventDespiteHumanOwner(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'actor-agent', 'user_id' => $user->getId()]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'actor plan',
                planType: 'test',
                agent: $agent,
                user: $user, // human owner present — agent must still win
            ),
        )->execute();

        $event = $plan->emitLedgerEvent('plan.actor.test');

        $this->assertSame('Agent', $event->actor_type);
        $this->assertSame($agent->getId(), $event->actor_id);
    }

    public function testAgentAssignedTaskAttributesToAgentViaParentPlan(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create(['name' => 'actor-agent-task', 'user_id' => $user->getId()]);

        $plan = new CreatePlanAction(
            new PlanData(app: $app, company: $company, title: 'p', planType: 'test', agent: $agent, user: $user),
        )->execute();

        $task = new AddTaskAction(
            $plan,
            new TaskData(plan: $plan, title: 't', status: TaskStatusEnum::PENDING),
        )->execute();

        $event = $task->emitLedgerEvent('plan.task.actor.test');

        $this->assertSame('Agent', $event->actor_type);
        $this->assertSame($agent->getId(), $event->actor_id);
    }

    public function testPlanWithoutAgentFallsBackToHumanOwner(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $plan = new CreatePlanAction(
            new PlanData(app: $app, company: $company, title: 'human plan', planType: 'test', user: $user),
        )->execute();

        $event = $plan->emitLedgerEvent('plan.human.test');

        $this->assertSame('User', $event->actor_type);
        $this->assertSame($user->getId(), $event->actor_id);
    }
}
