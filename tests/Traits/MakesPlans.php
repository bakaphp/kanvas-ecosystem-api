<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Plan / task / agent fixtures for the continuation-loop tests.
 *
 * Five test files built the same three helpers, which is five places for a required column to be
 * missed when the schema moves. A test using this trait needs `intelligence` in
 * `$connectionsToTransact` — plans, tasks and agents all live there, so without it the rows survive
 * the test and the next one sees them.
 */
trait MakesPlans
{
    /** A Neuron agent — the provider matters because only in-process agents can execute board work. */
    protected function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $type = AgentType::factory()->withAppId($app->getId())->create(['provider' => 'neuron']);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId(static::$cachedUser->getCurrentCompany()->getId())
            ->create(['agent_type_id' => $type->getId()]);
    }

    /**
     * @param array<string, mixed> $attributes Overrides — status, wake_count, max_wakes, output.
     */
    protected function makePlan(array $attributes = [], ?Agent $agent = null): Plan
    {
        $agent ??= $this->makeAgent();

        return Plan::create([
            'apps_id' => $agent->apps_id,
            'companies_id' => $agent->companies_id,
            'users_id' => static::$cachedUser->getId(),
            'agent_id' => $agent->getId(),
            'plan_type' => 'test',
            'title' => 'Test plan',
            'status' => PlanStatusEnum::ACTIVE->value,
            'priority' => 0,
            'completion_pct' => 0,
            'wake_count' => 0,
            ...$attributes,
        ]);
    }

    /**
     * Sequence defaults to 0 so tasks land in one band unless a test is specifically about ordering.
     */
    protected function makeTask(
        Plan $plan,
        TaskStatusEnum $status = TaskStatusEnum::PENDING,
        int $sequence = 0,
        ?string $blockedReason = null,
    ): Task {
        return Task::create([
            'plan_id' => $plan->getId(),
            'apps_id' => $plan->apps_id,
            'companies_id' => $plan->companies_id,
            'sequence' => $sequence,
            'title' => 'Task ' . $status->value,
            'status' => $status->value,
            'blocked_reason' => $blockedReason,
            'is_deleted' => 0,
        ]);
    }
}
