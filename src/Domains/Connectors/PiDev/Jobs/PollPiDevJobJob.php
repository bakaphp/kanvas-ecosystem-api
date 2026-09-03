<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\DataTransferObject\PiDevJob;
use Kanvas\Connectors\PiDev\Enums\JobStatusEnum;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\PiDev\Exceptions\PiDevApiException;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;
use Throwable;

class PollPiDevJobJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    // 2 polls/min. pi.dev's own hard limit is 1_800_000ms (30min); 62 attempts ≈ 31min so we poll
    // just past the ceiling to capture pi.dev's own terminal "time limit exceeded" state.
    private const int POLL_INTERVAL_SECONDS = 30;
    private const int MAX_ATTEMPTS = 62;
    private const array PROVIDER_RETRY_BACKOFF_SECONDS = [120, 900, 3600];

    public function __construct(
        public readonly Apps $app,
        public readonly int $taskId,
        public readonly int $attempt = 1,
    ) {
        $this->onQueue('agent-runtime');
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        /** @var Task $task */
        $task = Task::getById($this->taskId, $this->app);

        if ($this->taskIsTerminal($task)) {
            return;
        }

        $jobId = $task->get(TaskCustomFieldEnum::PIDEV_JOB_ID->value);
        if (! is_string($jobId) || $jobId === '') {
            return;
        }

        $client = $this->makeClient($task);

        try {
            $response = $client->getJob($jobId);
        } catch (PiDevApiException $e) {
            if ($e->status === 404) {
                $this->failLocally(
                    $task,
                    'pi.dev no longer knows this job (service restarted before it finished)',
                    '⚠️ pi.dev no longer knows this coding job — the server restarted before it finished.'
                );

                return;
            }

            throw $e;
        }

        $job = PiDevJob::fromApiResponse($response);

        // Checked before mirroring: mirrorOntoTask would mark the task BLOCKED, which taskIsTerminal
        // treats as final and would strand the retry.
        if ($this->shouldAutoRetry($task, $job)) {
            $this->postProgressComments($task, $client, $jobId);
            $this->scheduleAutoRetry($task, $job);

            return;
        }

        $this->mirrorOntoTask($task, $job);
        $this->postProgressComments($task, $client, $jobId);

        if ($job->isTerminal()) {
            $this->finalizePlan($task, $this->planStatusFor($job->status));
            $this->announce(
                $task,
                $this->terminalTitle($job->status),
                $this->terminalComment($task, $job)
            );

            return;
        }

        if ($this->attempt < self::MAX_ATTEMPTS) {
            self::dispatch($this->app, $this->taskId, $this->attempt + 1)
                ->delay(now()->addSeconds(self::POLL_INTERVAL_SECONDS));

            return;
        }

        $this->failLocally(
            $task,
            'Timed out waiting for pi.dev to finish the job',
            '⚠️ Timed out waiting for pi.dev to finish this coding job.'
        );
    }

    /**
     * Fail a job Kanvas-side (pi.dev forgot it, or we gave up waiting): mark the Task blocked, flip
     * the plan to failed, and tell the owner. Distinct from a pi.dev-reported terminal failure, which
     * flows through mirrorOntoTask + terminalComment.
     */
    private function failLocally(Task $task, string $blockedReason, string $announceMessage): void
    {
        $task->set(TaskCustomFieldEnum::PIDEV_STATUS->value, JobStatusEnum::FAILED->value);
        new UpdateTaskStatusAction(
            task: $task,
            newStatus: TaskStatusEnum::BLOCKED,
            blockedReason: $blockedReason,
        )->execute();
        $this->finalizePlan($task, PlanStatusEnum::FAILED);
        $this->announce($task, 'Coding job failed', $announceMessage);
    }

    private function shouldAutoRetry(Task $task, PiDevJob $job): bool
    {
        return $job->isRetryable() && $this->autoRetryCount($task) < count(self::PROVIDER_RETRY_BACKOFF_SECONDS);
    }

    private function autoRetryCount(Task $task): int
    {
        return (int) ($task->get(TaskCustomFieldEnum::PIDEV_AUTO_RETRY_COUNT->value) ?? 0);
    }

    private function scheduleAutoRetry(Task $task, PiDevJob $job): void
    {
        $attempt = $this->autoRetryCount($task) + 1;
        $delay = self::PROVIDER_RETRY_BACKOFF_SECONDS[$attempt - 1];

        $task->set(TaskCustomFieldEnum::PIDEV_AUTO_RETRY_COUNT->value, $attempt);
        $task->set(TaskCustomFieldEnum::PIDEV_STATUS->value, $job->status->value);

        RetryPiDevJobJob::dispatch($this->app, $task->getId())
            ->delay(now()->addSeconds($delay));

        $this->postPlanComment($task, sprintf(
            "🔁 pi.dev's provider rejected the run (%s). Nothing was charged and the task itself is fine — "
            . 'retrying automatically in %d minutes (attempt %d of %d).',
            $job->error ?? 'no reason given',
            (int) round($delay / 60),
            $attempt,
            count(self::PROVIDER_RETRY_BACKOFF_SECONDS),
        ));
    }

    private function terminalTitle(JobStatusEnum $status): string
    {
        return match ($status) {
            JobStatusEnum::COMPLETED => 'Coding job completed',
            JobStatusEnum::CANCELLED => 'Coding job cancelled',
            default => 'Coding job failed',
        };
    }

    /**
     * Post the outcome to the plan's Activities channel AND notify the plan's human owner out-of-band
     * (email + push + in-app bell) — so whoever dispatched it hears back without watching the UI.
     */
    private function announce(Task $task, string $title, string $message): void
    {
        $this->postPlanComment($task, $message);

        try {
            /** @var Plan|null $plan */
            $plan = $task->plan;
            $owner = $plan?->user;
            if ($plan === null || $owner === null) {
                return;
            }

            $owner->notify(new PlanProgressNotification(
                $plan,
                $title,
                $message,
                metadata: [
                    'task_id' => $task->getId(),
                    'pull_request_url' => $task->get(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value),
                    'repo' => $task->get(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value),
                ],
                via: ['mail', 'push'],
            ));
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function planStatusFor(JobStatusEnum $status): PlanStatusEnum
    {
        return match ($status) {
            JobStatusEnum::COMPLETED => PlanStatusEnum::DONE,
            JobStatusEnum::FAILED => PlanStatusEnum::FAILED,
            JobStatusEnum::CANCELLED => PlanStatusEnum::CANCELLED,
            default => PlanStatusEnum::ACTIVE,
        };
    }

    /**
     * Promote the coding-job plan out of `active` when its single task reaches terminal — task
     * status and plan status are independent, and nothing else moves it.
     */
    private function finalizePlan(Task $task, PlanStatusEnum $status): void
    {
        /** @var Plan|null $plan */
        $plan = $task->plan;
        if ($plan === null) {
            return;
        }

        $previousStatus = $plan->status;
        $plan->status = $status->value;
        if ($status === PlanStatusEnum::DONE) {
            $plan->completed_at = Carbon::now();
        }
        $plan->saveQuietly();

        // saveQuietly skips model events, so broadcast the plan's own status flip explicitly (as
        // UpdatePlanAction does) — the board updates the plan header live the instant the job ends,
        // not on next refresh. The task change already broadcast via UpdateTaskStatusAction upstream.
        $plan->broadcastChange(PlanChangeTypeEnum::UPDATED, previousStatus: $previousStatus);
    }

    private function terminalComment(Task $task, PiDevJob $job): string
    {
        $repo = (string) ($task->get(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value) ?? '');

        return match ($job->status) {
            JobStatusEnum::COMPLETED => '✅ Coding job completed' . ($repo !== '' ? " on `{$repo}`" : '') . ".\n\n"
                . 'Pull request: ' . ($job->pullRequestUrl ?? 'none reported')
                . ($job->result !== null ? "\n\n" . $job->result : ''),
            JobStatusEnum::FAILED => '⚠️ Coding job failed' . ($repo !== '' ? " on `{$repo}`" : '') . ".\n\n"
                . 'Reason: ' . ($job->error ?? 'unknown'),
            JobStatusEnum::CANCELLED => '🛑 Coding job was cancelled.'
                . ($job->pullRequestUrl !== null ? "\n\nA pull request was already opened: " . $job->pullRequestUrl : ''),
            default => 'Coding job finished with status: ' . $job->status->value,
        };
    }

    private function postPlanComment(Task $task, string $content): void
    {
        $plan = $task->plan;
        if ($plan === null) {
            return;
        }

        // Best-effort: PostPlanActivityMessageAction reports failures rather than throwing, so a
        // missing channel or social hiccup never breaks the poll.
        new PostPlanActivityMessageAction(
            plan: $plan,
            content: $content,
            verb: 'coding_job_result',
        )->execute();
    }

    /**
     * Post the agent's NEW narration since last tick as one progress comment. Only `text` frames are
     * kept (raw tool_start/tool_end pings would clutter the feed); a cursor custom field marks the last
     * event id so the SSE replay is never re-posted. Best-effort — a stream hiccup must not break polling.
     */
    private function postProgressComments(Task $task, Client $client, string $jobId): void
    {
        try {
            $cursor = (int) ($task->get(TaskCustomFieldEnum::PIDEV_EVENTS_CURSOR->value) ?? 0);
            $frames = $client->fetchJobEvents($jobId, $cursor);
            if ($frames === []) {
                return;
            }

            $maxId = $cursor;
            $narration = [];

            foreach ($frames as $frame) {
                $maxId = max($maxId, $frame['id']);
                if ($frame['event'] === 'text' && is_string($frame['data']) && trim($frame['data']) !== '') {
                    $narration[] = trim($frame['data']);
                }
            }

            if ($narration !== []) {
                $this->postPlanComment($task, '🔧 ' . implode("\n", $narration));
            }

            $task->set(TaskCustomFieldEnum::PIDEV_EVENTS_CURSOR->value, $maxId);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function mirrorOntoTask(Task $task, PiDevJob $job): void
    {
        $task->set(TaskCustomFieldEnum::PIDEV_STATUS->value, $job->status->value);

        if ($job->pullRequestUrl !== null) {
            $task->set(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value, $job->pullRequestUrl);
        }

        $targetStatus = $job->status->toTaskStatus();
        if ($targetStatus->value === $task->status) {
            return;
        }

        new UpdateTaskStatusAction(
            task: $task,
            newStatus: $targetStatus,
            result: $this->resultPayload($job),
            blockedReason: $job->status === JobStatusEnum::FAILED ? ($job->error ?? 'pi.dev job failed') : null,
        )->execute();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resultPayload(PiDevJob $job): ?array
    {
        if ($job->status !== JobStatusEnum::COMPLETED) {
            return null;
        }

        return array_filter([
            'summary' => $job->result,
            'pull_request_url' => $job->pullRequestUrl,
        ], static fn ($value): bool => $value !== null);
    }

    /**
     * Overridable so tests can drive the poller with canned pi.dev responses. A queued job cannot
     * take the Client on its constructor — a Guzzle handler stack does not survive serialization.
     */
    protected function makeClient(Task $task): Client
    {
        return new Client($this->app, $task->company);
    }

    private function taskIsTerminal(Task $task): bool
    {
        return TaskStatusEnum::tryFrom($task->status)?->isTerminal() ?? false;
    }
}
