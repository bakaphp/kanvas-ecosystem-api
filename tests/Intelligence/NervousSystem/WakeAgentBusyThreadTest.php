<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Sleep;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Mockery;
use Tests\Stubs\Intelligence\BusyThreadNeuronAgentStub;
use Tests\TestCase;
use Tests\Traits\MakesPlans;

/**
 * Two wakes for one plan share one thread. The second finds the first's run alive, waits the busy
 * window, and is then refused by the engine: a refusal is not a failed wake, it is a wake that has
 * to run later. Reported as a failure it burned the worker's retries within seconds against the same
 * busy thread and the wake was lost (KANVAS-ECOSYSTEM-6NS, a task_reopened wake on plan 926).
 */
class WakeAgentBusyThreadTest extends TestCase
{
    use DatabaseTransactions;
    use MakesPlans;

    protected $connectionsToTransact = [null, 'intelligence', 'social'];

    public function test_a_wake_behind_a_busy_thread_is_deferred_not_failed(): void
    {
        Bus::fake();
        Sleep::fake();

        $agent = $this->makeAgent();
        $agent->type->update(['handler' => BusyThreadNeuronAgentStub::class]);
        $plan = $this->makePlan([], $agent->refresh());

        $job = new WakeAgentForPlanJob($plan, WakeAgentForPlanJob::REASON_TASK_REOPENED, 'Task 1 was reset.');
        $queued = Mockery::mock(Job::class);
        $queued->shouldReceive('release')
            ->once()
            ->with(Mockery::on(static fn (int $delay): bool => $delay >= 30 && $delay <= 600));
        $job->setJob($queued);

        $job->handle();

        $this->assertSame(0, (int) $plan->refresh()->wake_count, 'A deferred wake gives its re-entry back.');
        $this->assertDatabaseMissing(
            'nervous_system_events',
            [
                'source_entity_type' => Plan::class,
                'source_entity_id' => $plan->getId(),
                'event_type' => 'plan.agent.invocation_failed',
            ],
            'intelligence',
        );
    }
}
