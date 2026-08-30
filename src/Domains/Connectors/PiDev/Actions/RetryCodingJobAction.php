<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PiDev\Actions;

use Kanvas\Connectors\PiDev\Client;
use Kanvas\Connectors\PiDev\DataTransferObject\PiDevWorkRequest;
use Kanvas\Connectors\PiDev\Enums\CustomFieldEnum;
use Kanvas\Connectors\PiDev\Enums\JobStatusEnum;
use Kanvas\Connectors\PiDev\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\PiDev\Jobs\PollPiDevJobJob;
use Kanvas\Connectors\PiDev\Services\PromptBuilder;
use Kanvas\Connectors\PiDev\Services\RepoAllowListService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * Re-run a finished pi.dev coding job in place, keeping the same Plan + Task.
 *
 * DispatchCodingJobAction always mints a fresh Plan, so retrying through it abandons the original
 * plan and its history. This queues a brand-new pi.dev job from the same payload and rewires the
 * existing task onto it, so a job that died for reasons unrelated to the work — an upstream provider
 * cap, a transient 5xx — resumes on the board where it left off.
 */
class RetryCodingJobAction
{
    public function __construct(
        private readonly Task $task,
        private readonly ?Client $client = null,
    ) {
    }

    public function execute(): Task
    {
        $previousJobId = $this->task->get(TaskCustomFieldEnum::PIDEV_JOB_ID->value);
        if (! is_string($previousJobId) || $previousJobId === '') {
            throw new ValidationException('This task never reached pi.dev, so there is nothing to retry');
        }

        /** @var Agent|null $agent */
        $agent = $this->task->agent;
        if ($agent === null) {
            throw new ValidationException('This coding job has no agent to retry it as');
        }

        $token = $agent->get(CustomFieldEnum::PIDEV_GITHUB_TOKEN->value);
        if (! is_string($token) || $token === '') {
            throw new ValidationException('This agent is no longer configured for pi.dev (missing GitHub token)');
        }

        $repo = RepoAllowListService::resolve(
            $agent,
            (string) $this->task->get(TaskCustomFieldEnum::PIDEV_REPO_SLUG->value)
        );

        $taskText = trim((string) ($this->task->description ?? ''));
        if ($taskText === '') {
            $taskText = trim((string) ($this->task->plan?->description ?? ''));
        }

        if ($taskText === '') {
            throw new ValidationException('The original task description is gone, so it cannot be re-sent');
        }

        $persona = $agent->get(CustomFieldEnum::PIDEV_SYSTEM_PROMPT->value);

        $request = new PiDevWorkRequest(
            agentId: $agent->uuid,
            githubToken: $token,
            workingGithubRepoUrl: (string) $repo['url'],
            task: $taskText,
            systemPrompt: PromptBuilder::build($repo, is_string($persona) ? $persona : null),
        );

        $client = $this->client ?? new Client($this->task->app, $this->task->company);
        $response = $client->queueWork($request->toApiPayload());

        $jobId = isset($response['jobId']) ? (string) $response['jobId'] : '';
        if ($jobId === '') {
            throw new ValidationException('pi.dev accepted the retry but returned no job id');
        }

        $this->rewireOntoNewJob($jobId, $response);
        $this->reopenPlan();

        PollPiDevJobJob::dispatch($this->task->app, $this->task->getId());

        return $this->task;
    }

    /**
     * @param array<string, mixed> $response
     */
    private function rewireOntoNewJob(string $jobId, array $response): void
    {
        $this->task->set(TaskCustomFieldEnum::PIDEV_JOB_ID->value, $jobId);
        $this->task->set(
            TaskCustomFieldEnum::PIDEV_STATUS->value,
            JobStatusEnum::fromApiResponse($response)->value
        );

        // The new job replays its SSE stream from zero, and any PR the dead run reported is stale.
        $this->task->set(TaskCustomFieldEnum::PIDEV_EVENTS_CURSOR->value, 0);
        $this->task->set(TaskCustomFieldEnum::PIDEV_PULL_REQUEST_URL->value, null);

        // PollPiDevJobJob bails on its first tick for anything it considers terminal, so the task has
        // to be walked back out of blocked/done before the poller will follow the new job at all.
        // UpdateTaskStatusAction only ever *sets* blocked_reason, so the stale one is cleared here.
        $this->task->blocked_reason = null;
        $this->task->result = null;

        new UpdateTaskStatusAction(
            task: $this->task,
            newStatus: TaskStatusEnum::PENDING,
        )->execute();
    }

    private function reopenPlan(): void
    {
        $plan = $this->task->plan;
        if ($plan === null) {
            return;
        }

        $previousStatus = $plan->status;
        $plan->status = PlanStatusEnum::ACTIVE->value;
        $plan->completed_at = null;
        $plan->saveQuietly();

        $plan->broadcastChange(PlanChangeTypeEnum::UPDATED, previousStatus: $previousStatus);
    }
}
