<?php

declare(strict_types=1);

namespace Tests\Traits;

use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\AccessControlList\Repositories\RolesRepository;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAction;
use Kanvas\Auth\DataTransferObject\RegisterInput;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Models\AgentType;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Actions\AssignCompanyAction;
use Kanvas\Users\Models\Users;

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
    /**
     * A Neuron agent — the provider matters because only in-process agents can execute board work.
     *
     * Its own dedicated user, never the acting test user. AgentFactory defaults `user_id` to 1, which
     * on a seeded database is nobody but on a fresh one IS the test user — so the agent and the
     * "human" in a test become the same row, and every "is this actor an agent?" check inverts:
     * self-approval fires on a real human, a human comment reads as agent-authored and wakes nobody.
     */
    protected function makeAgent(): Agent
    {
        $app = app(Apps::class);
        $type = AgentType::factory()->withAppId($app->getId())->create([
            'provider' => 'neuron',
        ]);

        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId(static::$cachedUser->getCurrentCompany()->getId())
            ->create([
                'agent_type_id' => $type->getId(),
                'user_id' => $this->makeAgentUser($app)->getId(),
            ]);
    }

    /**
     * The agent's own Kanvas user, provisioned the way HireAgentAction does it in production —
     * registered in the app and assigned to the company. A bare Users::factory() row has no
     * UsersAssociatedApps entry, so posting on the plan board throws "User not found" and
     * PostPlanActivityMessageAction swallows it into a null the assertion then reads as a pass.
     */
    private function makeAgentUser(Apps $app): Users
    {
        $company = static::$cachedUser->getCurrentCompany();

        $user = new RegisterUsersAction(RegisterInput::from([
            'email' => 'agent-' . fake()->unique()->uuid() . '@agents.test',
            'password' => bin2hex(random_bytes(8)),
            'firstname' => 'Test',
            'lastname' => 'Agent',
        ]))->execute();

        $branch = $company->branch ?? $company->branches()->first();
        $role = RolesRepository::getByNameFromCompany(RolesEnums::USER->value, $company, $app);

        new AssignCompanyAction($user, $branch, $role, $app)->execute();

        return $user;
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
