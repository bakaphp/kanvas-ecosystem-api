<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\Apps\Models\Apps;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Sessions\Models\Session;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\NervousSystem\Plan\Actions\ApprovePlanAction;
use Kanvas\NervousSystem\Plan\Actions\UpdatePlanAction;
use Kanvas\NervousSystem\Plan\DataTransferObject\Plan as PlanData;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Actions\CreateProjectAction;
use Kanvas\NervousSystem\Project\DataTransferObject\Project as ProjectData;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Kanvas\Users\Models\Users;
use Tests\Stubs\Intelligence\SalesNeuronAgentStub;
use Tests\TestCase;

/**
 * The delegation loop, closed at the plan level.
 *
 * Task-level handoffs already wake the plan's own agent. Whole-plan delegation — the unit the PM is
 * instructed to use — makes the worker the plan's agent, so every task wake is suppressed as
 * self-notification and nothing is left watching. The PM learned a plan had finished only if a human
 * told it; asked for a status it @mentioned the worker, which is dropped before delivery, and then
 * promised to relay an answer that could never arrive.
 */
final class DelegatedPlanOutcomeWakeTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['mysql', 'intelligence', 'social'];

    public function testFinishingADelegatedPlanWakesTheProjectManager(): void
    {
        Bus::fake();

        [$project, $plan] = $this->delegatedPlan();

        $this->complete($plan, PlanStatusEnum::DONE);

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->project->getId() === $project->getId()
                && $job->reason === WakeAgentForProjectJob::REASON_PLAN_OUTCOME
                && str_contains((string) $job->triggerMessage, (string) $plan->getId())
        );
    }

    /** A worker that gave up is the case a PM most needs to hear about. */
    public function testBlockingADelegatedPlanWakesTheProjectManager(): void
    {
        Bus::fake();

        [, $plan] = $this->delegatedPlan();

        $this->complete($plan, PlanStatusEnum::BLOCKED);

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->reason === WakeAgentForProjectJob::REASON_PLAN_OUTCOME
        );
    }

    /**
     * A terminal plan is re-saved as files land and verification runs. Each save carries the same
     * status, and waking on every one of them burns a PM turn per write.
     */
    public function testASecondSaveOnAnAlreadyTerminalPlanDoesNotWakeAgain(): void
    {
        [, $plan] = $this->delegatedPlan();
        $this->complete($plan, PlanStatusEnum::DONE);

        Bus::fake();
        $this->complete($plan->refresh(), PlanStatusEnum::DONE);

        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }

    /** A PM finishing its own plan already knows; waking it there bounces its work back at it. */
    public function testAPlanThePmWorkedItselfDoesNotWakeIt(): void
    {
        Bus::fake();

        $project = $this->project();
        $plan = $this->planFor($project, $project->pmAgent);

        $this->complete($plan, PlanStatusEnum::DONE);

        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }

    public function testAnInProgressPlanDoesNotWakeAnyone(): void
    {
        Bus::fake();

        [, $plan] = $this->delegatedPlan();

        $this->complete($plan, PlanStatusEnum::ACTIVE);

        Bus::assertNotDispatched(WakeAgentForProjectJob::class);
    }

    /**
     * A rejection is broadcast as REJECTED, which every other listener filters out. If this one
     * drops it too, turning a plan down cancels the work and tells nobody.
     */
    public function testRejectingAPlanWakesTheAgentThatAskedForIt(): void
    {
        Bus::fake();

        $project = $this->project();
        $plan = $this->planFor($project, $this->worker());
        $plan->created_by_agent_id = $project->pmAgent?->getId();
        $plan->status = PlanStatusEnum::AWAITING_APPROVAL->value;
        $plan->saveQuietly();

        new ApprovePlanAction($plan->refresh(), $this->user(), approved: false, reviewOutcome: 'Out of scope.')->execute();

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->reason === WakeAgentForProjectJob::REASON_PLAN_OUTCOME
                && $job->wakeAgent?->getId() === $project->pmAgent?->getId()
                && str_contains((string) $job->triggerMessage, 'REJECTED')
        );
    }

    /**
     * The creator is the agent that delegated, which stops being the project's PM the moment the
     * project changes hands. Asserted through handle(), not the dispatch — a job that accepts the
     * creator and then re-derives pmAgent anyway still passes a Bus::fake assertion.
     */
    public function testTheCreatorIsWokenNotTheProjectsCurrentPm(): void
    {
        $project = $this->project();
        $creator = $this->agentNamed('Creator ');
        $creator->type->update(['handler' => SalesNeuronAgentStub::class]);

        new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            'Plan 1 is now done.',
            wakeAgent: $creator->refresh(),
        )->handle();

        $invoked = Event::query()
            ->where('source_entity_type', Project::class)
            ->where('source_entity_id', $project->getId())
            ->where('event_type', 'project.agent.invoked')
            ->latest('id')
            ->first();

        $this->assertNotNull($invoked, 'the wake never ran');
        $this->assertSame($creator->getId(), $invoked->payload['agent_id']);
        $this->assertNotSame($project->pmAgent?->getId(), $invoked->payload['agent_id']);
    }

    /** A non-PM wake must not inherit the PM's thread and answer out of its history. */
    public function testANonPmWakeGetsItsOwnSession(): void
    {
        $project = $this->project();
        $creator = $this->agentNamed('Creator ');
        $creator->type->update(['handler' => SalesNeuronAgentStub::class]);
        $project->pmAgent?->type->update(['handler' => SalesNeuronAgentStub::class]);

        new WakeAgentForProjectJob($project, WakeAgentForProjectJob::REASON_HEARTBEAT, 'x')->handle();
        new WakeAgentForProjectJob(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            'x',
            wakeAgent: $creator->refresh(),
        )->handle();

        $sessions = Session::query()
            ->where('entity_namespace', Project::class)
            ->where('entity_id', $project->getId())
            ->pluck('agents_id');

        $this->assertCount(2, $sessions);
        $this->assertContains($creator->getId(), $sessions->all());
    }

    /** No creator recorded is the pre-existing case: the project's PM stays the right answer. */
    public function testAPlanWithNoRecordedCreatorStillWakesTheProjectPm(): void
    {
        Bus::fake();

        [$project, $plan] = $this->delegatedPlan();

        $this->complete($plan, PlanStatusEnum::DONE);

        Bus::assertDispatched(
            WakeAgentForProjectJob::class,
            fn (WakeAgentForProjectJob $job): bool => $job->wakeAgent?->getId() === $project->pmAgent?->getId()
        );
    }

    /**
     * @return array{0: Project, 1: Plan}
     */
    private function delegatedPlan(): array
    {
        $project = $this->project();

        return [$project, $this->planFor($project, $this->worker())];
    }

    private function complete(Plan $plan, PlanStatusEnum $status): void
    {
        new UpdatePlanAction(
            $plan,
            PlanData::forUpdate(
                $plan,
                $this->app(),
                $this->user()->getCurrentCompany(),
                ['status' => $status->value],
            ),
        )->execute();
    }

    private function project(): Project
    {
        return new CreateProjectAction(ProjectData::from(
            $this->app(),
            $this->user(),
            $this->user()->getCurrentCompany(),
            [
                'title' => 'Outcome ' . fake()->unique()->lexify('?????'),
                'agent_id' => $this->pm()->getId(),
            ],
        ))->execute();
    }

    private function planFor(Project $project, ?Agent $agent): Plan
    {
        return Plan::create([
            'apps_id' => $this->app()->getId(),
            'companies_id' => $this->user()->getCurrentCompany()->getId(),
            'users_id' => $this->user()->getId(),
            'project_id' => $project->getId(),
            'agent_id' => $agent?->getId(),
            'plan_type' => 'test',
            'title' => 'Delegated ' . fake()->unique()->lexify('?????'),
            'status' => PlanStatusEnum::ACTIVE->value,
            'priority' => 0,
            'completion_pct' => 0,
            'wake_count' => 0,
        ]);
    }

    private function pm(): Agent
    {
        return $this->agentNamed('PM ');
    }

    private function worker(): Agent
    {
        return $this->agentNamed('Worker ');
    }

    private function agentNamed(string $prefix): Agent
    {
        return Agent::factory()
            ->withAppId($this->app()->getId())
            ->withCompanyId($this->user()->getCurrentCompany()->getId())
            ->create([
                'user_id' => $this->user()->getId(),
                'name' => $prefix . fake()->unique()->lexify('?????'),
                'is_active' => true,
            ]);
    }

    private function app(): Apps
    {
        return app(Apps::class);
    }

    private function user(): Users
    {
        /** @var Users $user */
        $user = auth()->user();

        return $user;
    }
}
