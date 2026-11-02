<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\AgentRuntime\Harness\Concerns\PostsSessionActivity;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Models\Task;

/**
 * One more run of the same task after one that produced nothing. The task, plan and parent link are
 * untouched — to the task this is still its first attempt, just a second session under it — so the
 * parent keeps waiting and nobody is told the job failed until the retry has had its turn.
 */
class RetryHarnessSessionAction
{
    use PostsSessionActivity;

    public function __construct(
        private readonly AgentTaskSession $failed,
    ) {
    }

    public function execute(): AgentTaskSession
    {
        /** @var Task|null $task */
        $task = $this->failed->task;
        $agent = $this->failed->agent;

        if ($task === null || $agent === null) {
            throw new ValidationException('Session ' . $this->failed->getId() . ' has no task or agent to retry under.');
        }

        $input = (array) ($task->plan?->input ?? []);

        new DispatchHarnessTaskAction(
            agent: $agent,
            task: (string) $task->description,
            repoSlug: $this->failed->repo_slug,
            requestedBy: $this->failed->user,
            harness: $this->failed->harnessName(),
            referenceSlugs: array_values((array) ($input['reference_slugs'] ?? [])),
            attachmentIds: array_map(intval(...), (array) ($input['attachment_ids'] ?? [])),
            retryOf: $this->failed,
        )->execute();

        /** @var AgentTaskSession $retry */
        $retry = AgentTaskSession::forAgentJob($agent, $task->getId());

        $this->postToPlan(
            $this->failed,
            sprintf(
                'The first run produced nothing (%s). Retrying once on %s; if this run is empty too, the task is blocked.',
                (string) ($this->failed->error_message ?? 'no reason recorded'),
                $retry->model ?? 'the same model',
            ),
            'coding_job_retried',
        );

        $task->emitLedgerEvent('coding.session.retried', payload: [
            'failed_session_id' => $this->failed->getId(),
            'retry_session_id' => $retry->getId(),
            'model' => $retry->model,
        ]);

        return $retry;
    }
}
