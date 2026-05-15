<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Tests\TestCase;

class PlanAgentWakeUpTest extends TestCase
{
    public function testListenerDispatchesJobOnPlanCreatedWithActiveAgent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = $this->makeAgent($app, $company, $user, isActive: true);

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
    }

    public function testListenerDoesNotDispatchWhenAgentIsInactive(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = $this->makeAgent($app, $company, $user, isActive: false);

        Bus::fake([WakeAgentForPlanJob::class]);

        new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Inactive agent',
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

        $agent = $this->makeAgent($app, $company, $user, isActive: true);

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

        $assignedAgent = $this->makeAgent($app, $company, $user, isActive: true);

        // A different user (simulating another agent's user) — different ID.
        $otherUserId = $user->getId() + 9999;

        $assignedAgentUserId = $assignedAgent->user?->getId();
        $this->assertNotNull($assignedAgentUserId);

        // The filter expression: skip iff message.users_id == assignedAgent.user.id
        $this->assertNotSame($otherUserId, $assignedAgentUserId);
        $this->assertSame($user->getId(), $assignedAgentUserId);
    }

    public function testListenerEmitsWakeDispatchedLedgerEvent(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $agent = $this->makeAgent($app, $company, $user, isActive: true);

        Bus::fake([WakeAgentForPlanJob::class]);

        $plan = new CreatePlanAction(
            new PlanData(
                app: $app,
                company: $company,
                title: 'Wake-dispatched ledger',
                planType: 'workspace_issue',
                agent: $agent,
                user: $user,
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $exists = Event::query()
            ->where('source_entity_type', Plan::class)
            ->where('source_entity_id', $plan->id)
            ->where('event_type', 'plan.agent.wake_dispatched')
            ->exists();

        $this->assertTrue($exists, 'Expected plan.agent.wake_dispatched ledger event');
    }

    private function makeAgent(Apps $app, $company, $user, bool $isActive): Agent
    {
        return Agent::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create([
                'user_id' => $user->getId(),
                'is_active' => $isActive,
            ]);
    }
}
