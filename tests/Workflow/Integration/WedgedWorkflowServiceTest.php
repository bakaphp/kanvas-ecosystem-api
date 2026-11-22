<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration;

use App\Console\Commands\KanvasStatusCommand;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Contracts\Queue\Queue;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Queue\RedisQueue;
use Illuminate\Redis\Connections\Connection;
use Kanvas\Workflow\Enums\WedgedWorkflowCauseEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Kanvas\Workflow\Rules\DynamicRuleWorkflow;
use Kanvas\Workflow\Services\WedgedWorkflowService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\States\WorkflowFailedStatus;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowWaitingStatus;

/**
 * The two shapes found on 2026-11-18: a pending workflow behind an activity lock nothing will ever
 * release, and a waiting workflow whose activity never reported back.
 */
final class WedgedWorkflowServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = ['workflow'];

    public function testAPendingWorkflowBehindADeadActivityLockIsFoundAndCanRunOnceRepaired(): void
    {
        $storedWorkflow = $this->storedWorkflow(WorkflowPendingStatus::class);
        $lock = $this->holdActivityLock($storedWorkflow);
        $service = $this->serviceSeeing(['delayed' => [$this->workflowPayload($storedWorkflow, 540_000)]]);

        $wedged = $service->find(['default']);

        $this->assertCount(1, $wedged);
        $this->assertSame(WedgedWorkflowCauseEnum::ORPHANED_ACTIVITY_LOCK, $wedged[0]->cause);
        $this->assertSame([$lock], $wedged[0]->activityLocks);
        $this->assertSame(540_000, $wedged[0]->attempts);
        $this->assertFalse($this->workflowCanTakeItsTurn($storedWorkflow));

        $this->assertTrue($service->repair($wedged[0]));

        $this->assertTrue($this->workflowCanTakeItsTurn($storedWorkflow));
    }

    public function testAWaitingWorkflowWithNoActivityLeftIsFailed(): void
    {
        $storedWorkflow = $this->storedWorkflow(WorkflowWaitingStatus::class);
        $service = $this->serviceSeeing(['reserved' => [$this->workflowPayload($storedWorkflow, 500_000)]]);

        $wedged = $service->find(['default']);

        $this->assertSame(WedgedWorkflowCauseEnum::WAITING_WITHOUT_ACTIVITY, $wedged[0]->cause);
        $this->assertTrue($service->repair($wedged[0]));

        $storedWorkflow->refresh();
        $this->assertInstanceOf(WorkflowFailedStatus::class, $storedWorkflow->status);
        $this->assertSame(1, $storedWorkflow->exceptions()->count());
    }

    public function testAWaitingWorkflowWhoseActivityStillHoldsItsLockIsLeftAlone(): void
    {
        $storedWorkflow = $this->storedWorkflow(WorkflowWaitingStatus::class);
        $this->holdActivityLock($storedWorkflow);
        $service = $this->serviceSeeing(['ready' => [$this->workflowPayload($storedWorkflow, 5_000)]]);

        $wedged = $service->find(['default']);

        $this->assertSame(WedgedWorkflowCauseEnum::UNKNOWN, $wedged[0]->cause);
        $this->assertFalse($service->repair($wedged[0]));

        $storedWorkflow->refresh();
        $this->assertInstanceOf(WorkflowWaitingStatus::class, $storedWorkflow->status);
        $this->assertFalse($this->workflowCanTakeItsTurn($storedWorkflow));
    }

    public function testOneWorkflowQueuedTwiceIsReportedOnce(): void
    {
        $storedWorkflow = $this->storedWorkflow(WorkflowWaitingStatus::class);
        $service = $this->serviceSeeing([
            'delayed' => [$this->workflowPayload($storedWorkflow, 5_000)],
            'reserved' => [$this->workflowPayload($storedWorkflow, 5_001)],
        ]);

        $this->assertCount(1, $service->find(['default']));
        $this->assertSame(2, $service->countSpinningJobs(['default']));
    }

    public function testAHealthyWorkflowJobAndAnotherKindOfJobAreNotWorkflowsToRepair(): void
    {
        $storedWorkflow = $this->storedWorkflow(WorkflowPendingStatus::class);
        $otherJob = json_encode([
            'displayName' => 'App\Jobs\SomethingElse',
            'attempts' => 9_000,
            'data' => ['command' => 'O:8:"stdClass":0:{}'],
        ]);
        $service = $this->serviceSeeing([
            'ready' => [$this->workflowPayload($storedWorkflow, 3), $otherJob],
        ]);

        $this->assertSame([], $service->find(['default']));
        $this->assertSame(1, $service->countSpinningJobs(['default']));
    }

    public function testAQueueThatIsNotRedisHasNothingToScan(): void
    {
        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->andReturn(Mockery::mock(Queue::class));

        $service = new WedgedWorkflowService($queues, app(Cache::class), app(Encrypter::class));

        $this->assertSame([], $service->find(['default']));
        $this->assertSame(0, $service->countSpinningJobs(['default']));
    }

    public function testTheCommandOnlyReportsUntilToldToFix(): void
    {
        $storedWorkflow = $this->storedWorkflow(WorkflowWaitingStatus::class);
        $this->app->instance(
            WedgedWorkflowService::class,
            $this->serviceSeeing(['delayed' => [$this->workflowPayload($storedWorkflow, 5_000)]])
        );

        $this->artisan('kanvas:workflow-wedged', ['--queue' => ['default']])->assertFailed();
        $this->assertInstanceOf(WorkflowWaitingStatus::class, $storedWorkflow->refresh()->status);

        $this->artisan('kanvas:workflow-wedged', ['--queue' => ['default'], '--fix' => true])->assertSuccessful();
        $this->assertInstanceOf(WorkflowFailedStatus::class, $storedWorkflow->refresh()->status);
    }

    public function testTheCommandIsQuietWhenNothingIsSpinning(): void
    {
        $this->app->instance(WedgedWorkflowService::class, $this->serviceSeeing([]));

        $this->artisan('kanvas:workflow-wedged', ['--queue' => ['default']])
            ->expectsOutputToContain('No workflow job is past 100 attempts.')
            ->assertSuccessful();
    }

    public function testTheStatusScreenCountsSpinningJobs(): void
    {
        $storedWorkflow = $this->storedWorkflow(WorkflowWaitingStatus::class);
        $this->app->instance(
            WedgedWorkflowService::class,
            $this->serviceSeeing(['delayed' => [$this->workflowPayload($storedWorkflow, 5_000)]], ['default'])
        );

        $spinning = new ReflectionMethod(KanvasStatusCommand::class, 'spinningJobs');

        $this->assertSame(1, $spinning->invoke(new KanvasStatusCommand()));
    }

    private function storedWorkflow(string $status): StoredWorkflow
    {
        $storedWorkflow = StoredWorkflow::create(['class' => DynamicRuleWorkflow::class]);
        $storedWorkflow->status = $status;
        $storedWorkflow->save();

        return $storedWorkflow;
    }

    private function holdActivityLock(StoredWorkflow $storedWorkflow): string
    {
        $listKey = $this->overlap($storedWorkflow)->getActivitySemaphoreKey();
        $lock = $listKey . ':dead-attempt';

        app(Cache::class)->put($listKey, [$lock]);
        app(Cache::class)->put($lock, 1);

        return $lock;
    }

    private function workflowCanTakeItsTurn(StoredWorkflow $storedWorkflow): bool
    {
        $overlap = $this->overlap($storedWorkflow);
        $job = new DynamicRuleWorkflow($storedWorkflow);
        $locked = $overlap->lock($job);

        if ($locked) {
            $overlap->unlock($job);
        }

        return $locked;
    }

    private function overlap(StoredWorkflow $storedWorkflow): WithoutOverlappingMiddleware
    {
        return new WithoutOverlappingMiddleware($storedWorkflow->id, WithoutOverlappingMiddleware::WORKFLOW);
    }

    private function workflowPayload(StoredWorkflow $storedWorkflow, int $attempts): string
    {
        return json_encode([
            'displayName' => DynamicRuleWorkflow::class,
            'attempts' => $attempts,
            'data' => [
                'command' => app(Encrypter::class)->encrypt(serialize(new DynamicRuleWorkflow($storedWorkflow))),
            ],
        ]);
    }

    /**
     * @param array{ready?: list<string>, delayed?: list<string>, reserved?: list<string>} $jobs
     * @param list<string>|null $onlyQueues queues that hold those jobs; every other queue is empty
     */
    private function serviceSeeing(array $jobs, ?array $onlyQueues = null): WedgedWorkflowService
    {
        $held = function (string $key, string $state) use ($jobs, $onlyQueues): array {
            $queueName = explode(':', $key)[1];

            return $onlyQueues === null || in_array($queueName, $onlyQueues, true) ? ($jobs[$state] ?? []) : [];
        };

        $redis = Mockery::mock(Connection::class);
        $redis->shouldReceive('lrange')->andReturnUsing(fn (string $key) => $held($key, 'ready'));
        $redis->shouldReceive('zrange')->andReturnUsing(
            fn (string $key) => $held($key, str_ends_with($key, ':delayed') ? 'delayed' : 'reserved')
        );

        $queue = Mockery::mock(RedisQueue::class);
        $queue->shouldReceive('getConnection')->andReturn($redis);
        $queue->shouldReceive('getQueue')->andReturnUsing(fn (string $name) => 'queues:' . $name);

        $queues = Mockery::mock(QueueFactory::class);
        $queues->shouldReceive('connection')->andReturn($queue);

        return new WedgedWorkflowService($queues, app(Cache::class), app(Encrypter::class));
    }
}
