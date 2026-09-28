<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\CreatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\Actions\VerifyPlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use ReflectionMethod;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Who asked for the work, kept apart from who is doing it.
 *
 * `agent_id` cannot carry this: `assign_nervous_system_plan` overwrites it with the assignee and nulls
 * it outright when a human takes the plan, so the creator is erased by the first delegation. `users_id`
 * holds it today but only as a USER, and an agent's user is routinely a real person's account as well
 * (the PM of project 1834 writes as user 667) — so "which agent made this" is not recoverable from it.
 */
final class PlanCreatorAgentTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testTheCreatingAgentIsRecordedSeparatelyFromTheAssignee(): void
    {
        $creator = $this->makeAgent();
        $plan = $this->planCreatedBy($creator);

        $this->assertSame($creator->getId(), $plan->created_by_agent_id);
        $this->assertNull($plan->agent_id, 'A plan has no executor until it is delegated.');
        $this->assertSame($creator->getId(), $plan->createdByAgent?->getId());
    }

    /** The whole reason it is its own column: delegation must not erase who asked. */
    public function testDelegatingThePlanDoesNotOverwriteTheCreator(): void
    {
        $creator = $this->makeAgent();
        $worker = $this->makeAgent();
        $plan = $this->planCreatedBy($creator);

        $plan->agent_id = $worker->getId();
        $plan->saveQuietly();

        $plan = $plan->fresh();

        $this->assertSame($worker->getId(), $plan->agent_id);
        $this->assertSame($creator->getId(), $plan->created_by_agent_id);
    }

    /**
     * The outcome goes back to the agent that ASKED, not to whoever currently runs the project — which
     * is wrong once a project changes PM, and absent entirely on a plan with no project.
     */
    public function testAFinishedPlanWakesTheProjectOfTheCreatingAgent(): void
    {
        $creator = $this->makeAgent();
        $project = $this->projectRunBy($creator);
        $plan = $this->planCreatedBy($creator, $project);

        $plan->agent_id = $this->makeAgent()->getId();
        $plan->saveQuietly();

        Bus::fake();
        $this->complete($plan->fresh());

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->project->getId() === $project->getId()
                && $job->reason === WakeAgentForProjectJob::REASON_PLAN_OUTCOME
        );
    }

    /** An agent that finished a plan it asked for itself already knows; waking it bounces its own work back. */
    public function testAPlanTheCreatorWorkedItselfDoesNotWakeIt(): void
    {
        $creator = $this->makeAgent();
        $project = $this->projectRunBy($creator);
        $plan = $this->planCreatedBy($creator, $project);

        $plan->agent_id = $creator->getId();
        $plan->saveQuietly();

        Bus::fake();
        $this->complete($plan->fresh());

        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }

    /**
     * The path that actually finishes a plan.
     *
     * `update_nervous_system_plan` is the agent closing a plan by hand; the loop closes it through
     * `VerifyPlanAction`, which settled with `saveQuietly()` — so the one transition that means "the
     * work is over" was the one nobody heard, and the agent that asked for it was never told.
     */
    public function testAPlanSettledByVerificationStillWakesTheCreator(): void
    {
        $creator = $this->makeAgent();
        $project = $this->projectRunBy($creator);
        $plan = $this->planCreatedBy($creator, $project);

        $plan->agent_id = $this->makeAgent()->getId();
        $plan->saveQuietly();

        Bus::fake();
        $this->settleByVerification($plan->fresh());

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->project->getId() === $project->getId()
                && $job->reason === WakeAgentForProjectJob::REASON_PLAN_OUTCOME
        );
    }

    /**
     * A failed verification is a lap, not an outcome — it runs again on the next wake and often passes
     * seconds later. Plan 26531 told its PM "blocked" at 03:37:39 and "done" at 03:37:49: two wakes
     * for one result. A plan that STAYS blocked is still reported, by the project heartbeat.
     */
    public function testAFailedVerificationDoesNotWakeTheCreator(): void
    {
        $creator = $this->makeAgent();
        $project = $this->projectRunBy($creator);
        $plan = $this->planCreatedBy($creator, $project);

        $plan->agent_id = $this->makeAgent()->getId();
        $plan->saveQuietly();

        Bus::fake();
        $this->settleByVerification($plan->fresh(), passed: false);

        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }

    /** Plans made by a human, a cron or a workflow have no creating agent, and must still save. */
    public function testAPlanWithNoCreatingAgentIsStillValid(): void
    {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $this->app(),
                company: $this->human()->getCurrentCompany(),
                title: 'Human plan ' . fake()->unique()->lexify('?????'),
                planType: 'project_work',
                user: $this->human(),
                status: PlanStatusEnum::ACTIVE,
            ),
        )->execute();

        $this->assertNull($plan->created_by_agent_id);
        $this->assertNull($plan->createdByAgent);
    }

    /**
     * What `VerifyPlanAction::settle()` does on a pass, without running a verifier LLM: set the status
     * on a quiet save, then announce it. Reaching into the private method keeps the test on the real
     * code path rather than a copy of it.
     */
    private function settleByVerification(Plan $plan, bool $passed = true): void
    {
        $settle = new ReflectionMethod(VerifyPlanAction::class, 'settle');
        $settle->invoke(new VerifyPlanAction($plan), $passed);
    }

    /** Only UpdatePlanAction broadcasts the change the outcome listener rides on; a raw save is silent. */
    private function complete(Plan $plan): void
    {
        new UpdatePlanAction(
            $plan,
            PlanData::forUpdate(
                $plan,
                $this->app(),
                $this->human()->getCurrentCompany(),
                ['status' => PlanStatusEnum::DONE->value],
            ),
        )->execute();
    }

    private function planCreatedBy(Agent $creator, ?Project $project = null): Plan
    {
        $plan = new CreatePlanAction(
            new PlanData(
                app: $this->app(),
                company: $this->human()->getCurrentCompany(),
                title: 'Created ' . fake()->unique()->lexify('?????'),
                planType: 'project_work',
                user: $creator->user ?? $this->human(),
                status: PlanStatusEnum::ACTIVE,
                project: $project,
                createdByAgent: $creator,
            ),
        )->execute();

        return $plan->fresh();
    }

    private function projectRunBy(Agent $pm): Project
    {
        return new CreateProjectAction(ProjectData::from(
            $this->app(),
            $this->human(),
            $this->human()->getCurrentCompany(),
            [
                'title' => 'Creator ' . fake()->unique()->lexify('?????'),
                'agent_id' => $pm->getId(),
            ],
        ))->execute();
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function human(): Users
    {
        return static::$cachedUser;
    }
}
