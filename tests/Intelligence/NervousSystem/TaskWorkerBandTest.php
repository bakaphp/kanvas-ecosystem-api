<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Kanvas\NervousSystem\Plan\Actions\DispatchTaskBandAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Support\ApprovalPolicy;
use Kanvas\NervousSystem\Plan\Support\VerifierToolPolicy;
use Kanvas\NervousSystem\Plan\Support\WorkerToolPolicy;
use RuntimeException;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

class TaskWorkerBandTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence'];

    /** Tasks sharing a sequence are independent, so they go out together. */
    public function test_tasks_sharing_a_sequence_are_dispatched_as_one_batch(): void
    {
        Bus::fake();

        $plan = $this->makePlan();
        $this->makeTask($plan, sequence: 1);
        $this->makeTask($plan, sequence: 1);
        $this->makeTask($plan, sequence: 1);

        $result = new DispatchTaskBandAction($plan)->execute();

        $this->assertSame(3, $result['dispatched']);
        $this->assertSame(1, $result['sequence']);
        Bus::assertBatchCount(1);
    }

    /** A higher sequence waits — this is what preserves ordering when one task needs another's output. */
    public function test_only_the_lowest_open_sequence_is_dispatched(): void
    {
        Bus::fake();

        $plan = $this->makePlan();
        $this->makeTask($plan, sequence: 1);
        $this->makeTask($plan, sequence: 2);
        $this->makeTask($plan, sequence: 3);

        $result = new DispatchTaskBandAction($plan)->execute();

        $this->assertSame(1, $result['dispatched']);
        $this->assertSame(1, $result['sequence']);
    }

    /**
     * Sequences are auto-incremented and therefore distinct by default, so an untouched plan runs
     * serially — the safe behaviour, and the reason the tool description had to change.
     */
    public function test_default_distinct_sequences_degrade_to_serial(): void
    {
        Bus::fake();

        $plan = $this->makePlan();
        $this->makeTask($plan, sequence: 0);
        $this->makeTask($plan, sequence: 1);

        $this->assertSame(1, new DispatchTaskBandAction($plan)->execute()['dispatched']);
    }

    public function test_a_plan_with_nothing_pending_dispatches_nothing(): void
    {
        Bus::fake();

        $plan = $this->makePlan();
        $this->makeTask($plan, sequence: 1, status: TaskStatusEnum::DONE);

        $result = new DispatchTaskBandAction($plan)->execute();

        $this->assertSame(0, $result['dispatched']);
        $this->assertNull($result['sequence']);
        Bus::assertNothingBatched();
    }

    /** Board mutation, delegation, outbound and scheduling are not in a worker's toolset at all. */
    public function test_the_worker_boundary_denies_the_dangerous_verbs(): void
    {
        foreach ([
            'assign_nervous_system_plan',
            'add_nervous_system_task',
            'dispatch_coding_task',
            'dispatch_long_task',
            'send_email',
            'cronjob',
            'update_agent_instructions',
        ] as $denied) {
            $this->assertFalse(WorkerToolPolicy::permits($denied), $denied . ' should be denied');
        }
    }

    public function test_the_worker_boundary_still_allows_ordinary_work(): void
    {
        $this->assertTrue(WorkerToolPolicy::permits('search_leads'));
        $this->assertTrue(WorkerToolPolicy::permits('query_ar_aging'));
    }

    /** The policy must not leak into the next job on a long-running worker. */
    public function test_the_worker_boundary_is_cleared_even_when_the_turn_throws(): void
    {
        $this->assertFalse(WorkerToolPolicy::isActive());

        try {
            WorkerToolPolicy::within(function (): void {
                $this->assertTrue(WorkerToolPolicy::isActive());

                throw new RuntimeException('turn blew up');
            });
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse(WorkerToolPolicy::isActive());
    }

    /** The verifier is an allow-list: anything not demonstrably read-only is stripped. */
    public function test_the_verifier_boundary_permits_only_reads(): void
    {
        $this->assertTrue(VerifierToolPolicy::permits('read_my_ledger'));
        $this->assertTrue(VerifierToolPolicy::permits('query_ar_aging'));
        $this->assertTrue(VerifierToolPolicy::permits('capability_lookup'));

        $this->assertFalse(VerifierToolPolicy::permits('update_nervous_system_task_status'));
        $this->assertFalse(VerifierToolPolicy::permits('send_email'));
        $this->assertFalse(VerifierToolPolicy::permits('create_lead'));
    }

    /** Money, customers and self-modification keep the gate whatever the track record. */
    public function test_gated_tools_always_require_approval(): void
    {
        $plan = $this->verifiedPlan();

        $this->assertTrue(ApprovalPolicy::requiresApproval($plan, ['send_email']));
        $this->assertTrue(ApprovalPolicy::requiresApproval($plan, ['create_ar_invoice']));
        $this->assertTrue(ApprovalPolicy::requiresApproval($plan, ['update_agent_instructions']));
    }

    public function test_autonomy_is_earned_by_a_verification_record(): void
    {
        $unverified = $this->makePlan();
        $verified = $this->verifiedPlan();

        $this->assertTrue(ApprovalPolicy::requiresApproval($unverified, ['search_leads']));
        $this->assertFalse(ApprovalPolicy::requiresApproval($verified, ['search_leads']));
    }

    /** A failed check is not a record — otherwise "we checked and it was wrong" would grant autonomy. */
    public function test_a_failed_verification_does_not_count_as_a_record(): void
    {
        $plan = $this->makePlan();
        $plan->output = ['verification' => ['verified' => false, 'verdict' => 'could not confirm']];
        $plan->saveQuietly();

        $this->assertTrue(ApprovalPolicy::requiresApproval($plan->refresh(), ['search_leads']));
    }

    /** A plan that has passed a check — the only thing that earns any relaxation of the gate. */
    private function verifiedPlan(): Plan
    {
        $plan = $this->makePlan();
        $plan->output = ['verification' => ['verified' => true, 'verdict' => 'VERIFIED']];
        $plan->saveQuietly();

        return $plan->refresh();
    }
}
