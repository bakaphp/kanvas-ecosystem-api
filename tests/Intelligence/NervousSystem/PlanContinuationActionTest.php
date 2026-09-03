<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\NervousSystem\Plan\Actions\PlanContinuationAction;
use Kanvas\NervousSystem\Plan\Enums\ContinuationDecisionEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * The loop's control flow, exercised without a model. Every other part of this system needs an LLM to
 * test; this one does not, which is the reason it exists as a separate action at all.
 */
class PlanContinuationActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    public function test_dispatches_while_open_tasks_remain(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::DONE);
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $decision = new PlanContinuationAction($plan)->execute();

        $this->assertSame(ContinuationDecisionEnum::DISPATCH, $decision->verdict);
        $this->assertSame(1, $decision->openTasks);
    }

    public function test_verifies_when_every_task_is_finished(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::DONE);
        $this->makeTask($plan, TaskStatusEnum::SKIPPED);

        $decision = new PlanContinuationAction($plan)->execute();

        $this->assertSame(ContinuationDecisionEnum::VERIFY, $decision->verdict);
        $this->assertSame(2, $decision->doneTasks);
    }

    /** An empty plan has achieved nothing — calling it verified would close plans never worked. */
    public function test_extends_when_the_plan_has_no_tasks_at_all(): void
    {
        $decision = new PlanContinuationAction($this->makePlan())->execute();

        $this->assertSame(ContinuationDecisionEnum::EXTEND, $decision->verdict);
    }

    public function test_blocks_when_a_blocked_task_is_all_that_remains(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::DONE);
        $this->makeTask($plan, TaskStatusEnum::BLOCKED, blockedReason: 'Needs a signed W-9 from the vendor.');

        $decision = new PlanContinuationAction($plan)->execute();

        $this->assertSame(ContinuationDecisionEnum::BLOCK, $decision->verdict);
        $this->assertStringContainsString('signed W-9', $decision->reason);
    }

    /** A blocked task must not stop a plan that still has work it can do around it. */
    public function test_a_blocked_task_does_not_stop_a_plan_with_other_open_work(): void
    {
        $plan = $this->makePlan();
        $this->makeTask($plan, TaskStatusEnum::BLOCKED, blockedReason: 'waiting on legal');
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $decision = new PlanContinuationAction($plan)->execute();

        $this->assertSame(ContinuationDecisionEnum::DISPATCH, $decision->verdict);
        $this->assertSame(1, $decision->blockedTasks);
    }

    public function test_abandons_once_the_wake_budget_is_spent(): void
    {
        $plan = $this->makePlan(['wake_count' => 25, 'max_wakes' => 25]);
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $decision = new PlanContinuationAction($plan)->execute();

        $this->assertSame(ContinuationDecisionEnum::ABANDON, $decision->verdict);
        $this->assertStringContainsString('cap 25', $decision->reason);
    }

    /** The budget outranks everything, including work that could still be dispatched. */
    public function test_the_wake_budget_outranks_open_work(): void
    {
        $plan = $this->makePlan(['wake_count' => 99, 'max_wakes' => 3]);
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $this->assertSame(
            ContinuationDecisionEnum::ABANDON,
            new PlanContinuationAction($plan)->execute()->verdict,
        );
    }

    public function test_a_plan_without_its_own_cap_uses_the_default(): void
    {
        $plan = $this->makePlan(['wake_count' => PlanContinuationAction::DEFAULT_MAX_WAKES]);

        $this->assertSame(
            ContinuationDecisionEnum::ABANDON,
            new PlanContinuationAction($plan)->execute()->verdict,
        );
    }

    /** Intake is the state your own rule created: visible, chaseable, and never executable. */
    public function test_an_intake_plan_is_never_dispatched_even_with_open_tasks(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::INTAKE->value]);
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $decision = new PlanContinuationAction($plan)->execute();

        $this->assertSame(ContinuationDecisionEnum::BLOCK, $decision->verdict);
        $this->assertStringContainsString('intake', $decision->reason);
    }

    public function test_an_unapproved_plan_is_never_dispatched(): void
    {
        $plan = $this->makePlan(['status' => PlanStatusEnum::AWAITING_APPROVAL->value]);
        $this->makeTask($plan, TaskStatusEnum::PENDING);

        $decision = new PlanContinuationAction($plan)->execute();

        $this->assertSame(ContinuationDecisionEnum::BLOCK, $decision->verdict);
        $this->assertStringContainsString('approval', $decision->reason);
    }

    public function test_the_decision_carries_its_counts_for_the_ledger(): void
    {
        $plan = $this->makePlan(['wake_count' => 4]);
        $this->makeTask($plan, TaskStatusEnum::PENDING);
        $this->makeTask($plan, TaskStatusEnum::DONE);

        $payload = new PlanContinuationAction($plan)->execute()->toLedgerPayload();

        $this->assertSame('dispatch', $payload['verdict']);
        $this->assertSame(1, $payload['open_tasks']);
        $this->assertSame(1, $payload['done_tasks']);
        $this->assertSame(4, $payload['wake_count']);
    }
}
