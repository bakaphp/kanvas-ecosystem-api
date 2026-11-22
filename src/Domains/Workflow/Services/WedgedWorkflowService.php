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
use Workflow\Middleware\WithoutOverlappingMiddleware;
use Workflow\Models\StoredWorkflow;
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
        $wedged = [];

        foreach ($this->spinningPayloads($queueNames, $minAttempts) as [$queueName, $payload]) {
            $storedWorkflow = $this->storedWorkflowFrom($payload);

            if ($storedWorkflow === null || isset($wedged[$storedWorkflow->id])) {
                continue;
            }

            $locks = $this->heldActivityLocks($storedWorkflow);

            $wedged[$storedWorkflow->id] = new WedgedWorkflow(
                storedWorkflow: $storedWorkflow,
                queue: $queueName,
                attempts: (int) $payload['attempts'],
                activityLocks: $locks,
                cause: $this->diagnose($storedWorkflow, $locks),
            );
        }

        return array_values($wedged);
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

        if (! is_string($command) || ! is_a($payload['displayName'] ?? '', Workflow::class, true)) {
            return null;
        }

        try {
            $job = unserialize(str_starts_with($command, 'O:') ? $command : $this->encrypter->decrypt($command));
        } catch (Throwable) {
            // The stored workflow was pruned from under its job, which then fails on its own.
            return null;
        }

        return $job instanceof Workflow ? $job->storedWorkflow : null;
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
