<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Services;

use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Queue\RedisQueue;
use Kanvas\Workflow\DataTransferObject\WedgedWorkflow;
use Kanvas\Workflow\Enums\WedgedWorkflowCauseEnum;
use RuntimeException;
use Throwable;
use Workflow\Activity;
use Workflow\ChildWorkflow;
use Workflow\Exception as ExceptionJob;
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
use Workflow\Signal;
use Workflow\States\WorkflowPendingStatus;
use Workflow\States\WorkflowWaitingStatus;
use Workflow\Workflow;

/**
 * A workflow job that can never take its turn is released forever instead of failing, so it never
 * reaches failed_jobs and a queue full of them reads as almost idle. The only trace is the attempt
 * counter on the Redis payload, which is what this reads.
 */
class WedgedWorkflowService
{
    /**
     * Past the zero-delay band of DynamicRuleWorkflow::releaseDelayFor(), so a job this far along
     * has been blocked for minutes, not for the instant an activity takes to unlock.
     */
    public const int DEFAULT_MIN_ATTEMPTS = 100;

    private const int SCAN_LIMIT = 500;

    /**
     * Each of these takes the workflow's own lock and is released while activity locks are held.
     */
    private const array WAITS_ON_WORKFLOW_LOCK = [
        Workflow::class,
        Signal::class,
        ExceptionJob::class,
    ];

    /**
     * The last two wait for the workflow lock to be free instead, so a held activity lock is not
     * what blocks them and neither repair applies.
     */
    private const array ENGINE_JOBS = [
        ...self::WAITS_ON_WORKFLOW_LOCK,
        Activity::class,
        ChildWorkflow::class,
    ];

    public function __construct(
        private readonly QueueFactory $queues,
        private readonly Cache $cache,
        private readonly Encrypter $encrypter
    ) {
    }

    /**
     * Payload-only, so it is cheap enough for a status screen: nothing is decrypted.
     *
     * @param list<string> $queueNames
     */
    public function countSpinningJobs(array $queueNames, int $minAttempts = self::DEFAULT_MIN_ATTEMPTS): int
    {
        return iterator_count($this->spinningPayloads($queueNames, $minAttempts));
    }

    /**
     * @param list<string> $queueNames
     *
     * @return list<WedgedWorkflow> one per stored workflow, however many jobs it has queued
     */
    public function find(array $queueNames, int $minAttempts = self::DEFAULT_MIN_ATTEMPTS): array
    {
        return $this->scan($queueNames, $minAttempts)[0];
    }

    /**
     * The wedged workflows, then what countSpinningJobs() counts and no stored workflow explains: a
     * job that is not the engine's, or one whose workflow row is gone, grouped by job and queue with
     * the highest attempts seen. One pass for both, because tying a payload to its workflow decrypts
     * it and loads its models.
     *
     * @param list<string> $queueNames
     *
     * @return array{0: list<WedgedWorkflow>, 1: list<array{job: string, queue: string, jobs: int, attempts: int}>}
     */
    public function scan(array $queueNames, int $minAttempts = self::DEFAULT_MIN_ATTEMPTS): array
    {
        $wedged = [];
        $others = [];

        foreach ($this->spinningPayloads($queueNames, $minAttempts) as [$queueName, $payload]) {
            $storedWorkflow = $this->storedWorkflowFrom($payload);
            $attempts = (int) $payload['attempts'];

            if ($storedWorkflow === null) {
                $job = (string) ($payload['displayName'] ?? 'unknown');
                $key = $queueName . '|' . $job;
                $others[$key] = [
                    'job' => $job,
                    'queue' => $queueName,
                    'jobs' => ($others[$key]['jobs'] ?? 0) + 1,
                    'attempts' => max($others[$key]['attempts'] ?? 0, $attempts),
                ];

                continue;
            }

            if (isset($wedged[$storedWorkflow->id])) {
                continue;
            }

            $locks = $this->heldActivityLocks($storedWorkflow);

            $wedged[$storedWorkflow->id] = new WedgedWorkflow(
                storedWorkflow: $storedWorkflow,
                job: $payload['displayName'],
                queue: $queueName,
                attempts: $attempts,
                activityLocks: $locks,
                cause: $this->isOneOf($payload, self::WAITS_ON_WORKFLOW_LOCK)
                    ? $this->diagnose($storedWorkflow, $locks)
                    : WedgedWorkflowCauseEnum::UNKNOWN,
            );
        }

        return [array_values($wedged), array_values($others)];
    }

    /**
     * Clearing a lock under an activity that is slow but alive lets the workflow run beside it,
     * so this is only ever called on a human's say-so.
     */
    public function repair(WedgedWorkflow $wedged): bool
    {
        if ($wedged->cause === WedgedWorkflowCauseEnum::ORPHANED_ACTIVITY_LOCK) {
            foreach ($wedged->activityLocks as $lock) {
                $this->cache->forget($lock);
            }
            $this->cache->forget($this->activityLockListKey($wedged->storedWorkflow));

            return true;
        }

        if ($wedged->cause === WedgedWorkflowCauseEnum::WAITING_WITHOUT_ACTIVITY) {
            $wedged->storedWorkflow->toWorkflow()->fail(
                new RuntimeException('Wedged: waiting on an activity that never reported back.')
            );

            return true;
        }

        return false;
    }

    /**
     * @param list<string> $locks
     */
    private function diagnose(StoredWorkflow $storedWorkflow, array $locks): WedgedWorkflowCauseEnum
    {
        $pending = $storedWorkflow->status instanceof WorkflowPendingStatus;
        $waiting = $storedWorkflow->status instanceof WorkflowWaitingStatus;

        return match (true) {
            $pending && $locks !== [] => WedgedWorkflowCauseEnum::ORPHANED_ACTIVITY_LOCK,
            $waiting && $locks === [] => WedgedWorkflowCauseEnum::WAITING_WITHOUT_ACTIVITY,
            default => WedgedWorkflowCauseEnum::UNKNOWN,
        };
    }

    /**
     * @return list<string>
     */
    private function heldActivityLocks(StoredWorkflow $storedWorkflow): array
    {
        $listed = $this->cache->get($this->activityLockListKey($storedWorkflow), []);

        return array_values(array_filter(
            is_array($listed) ? $listed : [],
            fn (mixed $lock): bool => is_string($lock) && $this->cache->has($lock)
        ));
    }

    private function activityLockListKey(StoredWorkflow $storedWorkflow): string
    {
        return new WithoutOverlappingMiddleware(
            $storedWorkflow->id,
            WithoutOverlappingMiddleware::WORKFLOW
        )->getActivitySemaphoreKey();
    }

    private function storedWorkflowFrom(array $payload): ?StoredWorkflow
    {
        $command = $payload['data']['command'] ?? null;

        // Any other job is left serialized: restoring it would load whatever models it carries.
        if (! is_string($command) || ! $this->isOneOf($payload, self::ENGINE_JOBS)) {
            return null;
        }

        try {
            $job = unserialize(str_starts_with($command, 'O:') ? $command : $this->encrypter->decrypt($command));
        } catch (Throwable) {
            // The stored workflow was pruned from under its job, which then fails on its own.
            return null;
        }

        // A child reports back to its parent, and the parent's lock is the one it waits on.
        $storedWorkflow = $job instanceof ChildWorkflow
            ? $job->parentWorkflow
            : ($job->storedWorkflow ?? null);

        return $storedWorkflow instanceof StoredWorkflow ? $storedWorkflow : null;
    }

    /**
     * @param list<class-string> $classes
     */
    private function isOneOf(array $payload, array $classes): bool
    {
        $job = $payload['displayName'] ?? '';

        return is_string($job) && array_any($classes, fn (string $class): bool => is_a($job, $class, true));
    }

    /**
     * @param list<string> $queueNames
     *
     * @return iterable<array{0: string, 1: array<string, mixed>}>
     */
    private function spinningPayloads(array $queueNames, int $minAttempts): iterable
    {
        $connection = $this->queues->connection();

        if (! $connection instanceof RedisQueue) {
            return;
        }

        $redis = $connection->getConnection();

        foreach ($queueNames as $queueName) {
            $key = $connection->getQueue($queueName);

            // A released job is always in exactly one of these three.
            $raw = [
                ...$redis->lrange($key, 0, self::SCAN_LIMIT),
                ...$redis->zrange($key . ':delayed', 0, self::SCAN_LIMIT),
                ...$redis->zrange($key . ':reserved', 0, self::SCAN_LIMIT),
            ];

            foreach ($raw as $item) {
                $payload = is_string($item) ? json_decode($item, true) : null;

                if (is_array($payload) && (int) ($payload['attempts'] ?? 0) >= $minAttempts) {
                    yield [$queueName, $payload];
                }
            }
        }
    }
}
