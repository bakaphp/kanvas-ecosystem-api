<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Enums\SetupStatusEnum;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\InboundClaim;
use Kanvas\Communication\Support\ProviderRegistry;
use Throwable;

/**
 * Phases B and C of a disconnect. Each task runs under the same resource lock provisioning takes
 * and asks the vendor first: only a value we set is removed, so a cleanup that runs after a new
 * owner registered the number finds their value, supersedes itself, and touches nothing.
 */
final class RunConnectionCleanupAction
{
    private const int LEASE_MINUTES = 5;
    private const array BACKOFF_MINUTES = [1, 5, 15, 30, 60];

    /**
     * Decision Z: past this, the claim is released even with tasks left, so a vendor outage does not
     * hold the customer's number hostage. The remaining tasks keep running, still ownership-checked.
     */
    private const int FORCE_RELEASE_AFTER_MINUTES = 60;
    private const int FORCE_RELEASE_AFTER_ATTEMPTS = 6;

    public function __construct(
        private readonly Connection $connection
    ) {
    }

    public function execute(): Connection
    {
        if ($this->connection->setup_status !== SetupStatusEnum::DISCONNECTING) {
            return $this->connection;
        }

        $provisioner = app(ProviderRegistry::class)->provisioner($this->connection->provider);

        foreach ($this->dueTasks() as $task) {
            $this->run($task, fn (CleanupTask $task) => $provisioner->cleanup($task));
        }

        $tasks = $this->connection->cleanupTasks()->get();
        $finished = $tasks->every(fn (CleanupTask $task): bool => $task->status->isFinished());

        if ($finished) {
            DB::connection('workflow')->transaction(function () {
                $this->releaseClaim();
                $this->connection->forceFill(['setup_status' => SetupStatusEnum::DISCONNECTED])->saveOrFail();
            });

            return $this->connection;
        }

        $waitedTooLong = $this->connection->disconnect_requested_at?->lte(now()->subMinutes(self::FORCE_RELEASE_AFTER_MINUTES));
        $triedTooOften = $tasks->contains(fn (CleanupTask $task): bool => $task->attempts >= self::FORCE_RELEASE_AFTER_ATTEMPTS);

        if ($waitedTooLong || $triedTooOften) {
            $this->releaseClaim();
        }

        return $this->connection;
    }

    /**
     * Pending tasks that are due, and running ones whose worker's lease ran out.
     */
    private function dueTasks()
    {
        return $this->connection->cleanupTasks()
            ->where(fn ($query) => $query
                ->where(fn ($pending) => $pending
                    ->where('status', CleanupTaskStatusEnum::PENDING->value)
                    ->where(fn ($due) => $due->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
                ->orWhere(fn ($running) => $running
                    ->where('status', CleanupTaskStatusEnum::RUNNING->value)
                    ->where('lease_until', '<', now())))
            ->get();
    }

    /**
     * @param callable(CleanupTask): CleanupTaskStatusEnum $cleanup
     */
    private function run(CleanupTask $task, callable $cleanup): void
    {
        $lock = Cache::lock(
            EnsureCommunicationSetupAction::resourceLock($this->connection->provider, $task->resource_key),
            self::LEASE_MINUTES * 60
        );

        if (! $lock->get()) {
            return;
        }

        try {
            $task->forceFill([
                'status' => CleanupTaskStatusEnum::RUNNING,
                'lease_until' => now()->addMinutes(self::LEASE_MINUTES),
                'attempts' => $task->attempts + 1,
            ])->saveOrFail();

            $task->forceFill(['status' => $cleanup($task), 'lease_until' => null, 'last_error' => null])->saveOrFail();
        } catch (Throwable $exception) {
            report($exception);

            $task->forceFill([
                'status' => CleanupTaskStatusEnum::PENDING,
                'lease_until' => null,
                'last_error' => $exception->getMessage(),
                'next_attempt_at' => now()->addMinutes(self::BACKOFF_MINUTES[min($task->attempts, count(self::BACKOFF_MINUTES)) - 1]),
            ])->saveOrFail();
        } finally {
            $lock->release();
        }
    }

    private function releaseClaim(): void
    {
        InboundClaim::query()->where('connection_id', $this->connection->getId())->delete();
    }
}
