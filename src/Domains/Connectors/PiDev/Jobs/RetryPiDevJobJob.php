<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Actions\RetryCodingJobAction;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Throwable;

/**
 * Carries out an automatic retry after PollPiDevJobJob has waited out the backoff. Separate from the
 * poller so the wait is the queue's delay rather than a worker holding a slot open.
 */
final class RetryPiDevJobJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly int $taskId,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var Task $task */
        $task = Task::getById($this->taskId, $this->app);

        try {
            new RetryCodingJobAction($task)->execute();
        } catch (Throwable $e) {
            report($e);
            $this->blockAfterFailedRetry($task, $e);
        }
    }

    /**
     * If the retry itself cannot be queued the task must not sit in limbo — land it on the same
     * blocked state the original failure would have produced, carrying why the recovery failed.
     */
    private function blockAfterFailedRetry(Task $task, Throwable $e): void
    {
        new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::BLOCKED,
            blockedReason: 'Automatic retry could not be queued: ' . $e->getMessage(),
        )->execute();

        $plan = $task->plan;
        if ($plan !== null) {
            $plan->status = PlanStatusEnum::FAILED->value;
            $plan->saveQuietly();

            new PostPlanActivityMessageAction(
                plan: $plan,
                content: '⚠️ Automatic retry of the coding job could not be queued: ' . $e->getMessage(),
                verb: 'coding_job_result',
            )->execute();
        }
    }
}
