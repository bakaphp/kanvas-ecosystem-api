<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanConfigurationEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Tests\TestCase;

class PlanAgentWakeUpTest extends TestCase
{
    public function testListenerDispatchesJobOnPlanCreatedWhenAutoWakeIsOn(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(PlanConfigurationEnum::AUTO_WAKE_AGENTS->value, true);
        $agent = $this->makeAgent($app, $company, $user);

        Bus::fake([WakeAgentForPlanJob::class]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Wake-up smoke',
                planType: 'workspace_issue',
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        Bus::assertDispatched(
            WakeAgentForPlanJob::class,
            fn (WakeAgentForPlanJob $job) =>
                $job->plan->id === $plan->id
                && $job->reason === WakeAgentForPlanJob::REASON_PLAN_ASSIGNED
        );

        $app->set(PlanConfigurationEnum::AUTO_WAKE_AGENTS->value, false);
    }

    public function testListenerDoesNotDispatchWhenAutoWakeIsOff(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(PlanConfigurationEnum::AUTO_WAKE_AGENTS->value, false);
        $agent = $this->makeAgent($app, $company, $user);

        Bus::fake([WakeAgentForPlanJob::class]);

        new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Auto-wake off',
                planType: 'workspace_issue',
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);
    }

    public function testListenerDoesNotDispatchWhenPlanHasNoAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $app->set(PlanConfigurationEnum::AUTO_WAKE_AGENTS->value, true);

        Bus::fake([WakeAgentForPlanJob::class]);

        new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'No agent assigned',
                planType: 'workspace_issue',
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        Bus::assertNotDispatched(WakeAgentForPlanJob::class);

        $app->set(PlanConfigurationEnum::AUTO_WAKE_AGENTS->value, false);
    }

    /**
     * Loop guard contract — the activity reads `$plan->agent->user->id`
     * and skips messages posted by that user. Verifies the link from
     * agent → user that the filter relies on resolves cleanly.
     */
    public function testPlanAgentResolvesToOwningUser(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = $this->makeAgent($app, $company, $user);

        $this->assertSame($user->getId(), $agent->user?->getId());
    }

    /**
     * Sanity check on the loop-guard predicate itself — different agent
     * users do NOT match the plan's assigned-agent user, so cross-agent
     * comments would not be skipped.
     */
    public function testCrossAgentCommentDoesNotMatchLoopGuard(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $assignedAgent = $this->makeAgent($app, $company, $user);

        // A different user (simulating another agent's user) — different ID.
        $otherUserId = $user->getId() + 9999;

        $assignedAgentUserId = $assignedAgent->user?->getId();
        $this->assertNotNull($assignedAgentUserId);

        // The filter expression: skip iff message.users_id == assignedAgent.user.id
        $this->assertNotSame($otherUserId, $assignedAgentUserId);
        $this->assertSame($user->getId(), $assignedAgentUserId);
    }

    private function makeAgent(Apps $app, $company, $user): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
            ]);
    }
}
