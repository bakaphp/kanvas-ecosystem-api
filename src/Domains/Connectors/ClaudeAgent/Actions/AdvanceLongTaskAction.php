<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ClaudeAgent\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\ClaudeAgent\Client;
use Kanvas\Connectors\ClaudeAgent\DataTransferObject\DrainResult;
use Kanvas\Connectors\ClaudeAgent\Enums\DrainOutcomeEnum;
use Kanvas\Connectors\ClaudeAgent\Enums\TaskCustomFieldEnum;
use Kanvas\Connectors\ClaudeAgent\Exceptions\ClaudeAgentApiException;
use Kanvas\Connectors\ClaudeAgent\Services\CustomToolBridgeService;
use Kanvas\Connectors\ClaudeAgent\Services\EventDrainService;
use Kanvas\Connectors\ClaudeAgent\Traits\ReportsAndContinues;
use Kanvas\Connectors\ClaudeAgent\Traits\ResolvesClaudeClient;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Actions\PostPlanActivityMessageAction;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Notifications\PlanProgressNotification;

/**
 * Advance one async task by a single tick: drain what the session has produced, serve any Kanvas
 * tool it is blocked on, mirror progress onto the board, and write a terminal status when it ends.
 *
 * Lives in an action rather than the job so it can be driven with an injected Client — the job is
 * serialized and cannot hold one. **This is the only place a terminal status is written for an async
 * run**; the agent's own text is narration, never a state transition.
 */
class AdvanceLongTaskAction
{
    use ReportsAndContinues;
    use ResolvesClaudeClient;

    /**
     * Wall-clock, not an attempt count. Unlike pi.dev (which caps itself at 30 minutes) a hosted
     * session has no ceiling of its own, so this is the only thing between a wedged run and a task
     * that polls forever.
     */
    public const int MAX_RUNTIME_HOURS = 12;

    public function __construct(
        protected readonly Task $task,
        protected readonly ?Client $client = null,
    ) {
    }

    /**
     * @return bool Whether the caller should schedule another tick.
     */
    public function execute(): bool
    {
        if ($this->isTerminal()) {
            return false;
        }

        $sessionId = (string) ($this->task->get(TaskCustomFieldEnum::CLAUDE_SESSION_ID->value) ?? '');

        if ($sessionId === '') {
            return $this->block('No hosted session was recorded for this task.');
        }

        if ($this->exceededMaxRuntime()) {
            return $this->block(sprintf('Exceeded the maximum runtime of %d hours.', self::MAX_RUNTIME_HOURS));
        }

        $agent = $this->task->agent;

        if (! $agent instanceof Agent) {
            return $this->block('The agent that owned this task no longer exists.');
        }

        $client = $this->claudeClient($agent->app, $agent->company);

        try {
            $result = $this->drain($client, $sessionId);
        } catch (ClaudeAgentApiException $e) {
            // A session the platform has forgotten is gone for good — retrying only burns ticks
            // against a 404 until the runtime ceiling.
            if ($e->status === 404) {
                return $this->block('The hosted session no longer exists (it was deleted or expired).');
            }

            throw $e;
        }

        $this->storeCursor($result->cursor);
        // Cumulative, so every tick refreshes the day's figure rather than adding to it.
        $this->bestEffort(fn () => new RecordSessionUsageAction($agent, $sessionId, $result->usage)->execute());
        $this->postProgress($result->text);

        if ($result->outcome === DrainOutcomeEnum::AWAITING_CLIENT) {
            $this->serveTools($client, $sessionId, $agent, $result);

            return true;
        }

        if ($result->outcome === DrainOutcomeEnum::TIMED_OUT) {
            return true;
        }

        // Artifacts before the terminal status: the notification that fires from finish() tells the
        // owner the task is done, and it should not arrive before the files it refers to exist.
        $plan = $this->task->plan;
        $this->bestEffort(fn () => new PullSessionOutputsAction($plan, $plan?->user, $sessionId, $client)->execute());
        $this->finish($result);

        return false;
    }

    /** One pass per tick — the caller re-schedules rather than holding a worker open. */
    protected function drain(Client $client, string $sessionId): DrainResult
    {
        $cursor = (string) ($this->task->get(TaskCustomFieldEnum::CLAUDE_EVENT_CURSOR->value) ?? '');

        return new EventDrainService(
            $client,
            $sessionId,
            $cursor !== '' ? $cursor : null,
            deadlineMs: 0,
            pollIntervalMs: 0,
        )->drain();
    }

    protected function serveTools(Client $client, string $sessionId, Agent $agent, DrainResult $result): void
    {
        if ($result->pendingToolCalls !== []) {
            $client->sendEvents($sessionId, new CustomToolBridgeService($agent)->resultEvents($result->pendingToolCalls));
        }
    }

    protected function finish(DrainResult $result): void
    {
        $this->task->set(TaskCustomFieldEnum::CLAUDE_STATUS->value, $result->outcome->value);

        [$status, $blockedReason] = match ($result->outcome) {
            DrainOutcomeEnum::COMPLETED, DrainOutcomeEnum::TERMINATED => [TaskStatusEnum::DONE, null],
            DrainOutcomeEnum::BUDGET_REACHED => [
                TaskStatusEnum::BLOCKED,
                'The session reached its spend limit. Raise or remove the budget to continue.',
            ],
            default => [
                TaskStatusEnum::BLOCKED,
                'The agent did not complete this task'
                    . ($result->stopReason !== null ? " (stop reason: {$result->stopReason})." : '.'),
            ],
        };

        new UpdateTaskStatusAction(
            task: $this->task,
            newStatus: $status,
            result: $result->text !== '' ? ['summary' => $result->text] : null,
            blockedReason: $blockedReason,
        )->execute();

        $this->announce($result, $blockedReason);
    }

    protected function block(string $reason): bool
    {
        $this->task->set(TaskCustomFieldEnum::CLAUDE_STATUS->value, DrainOutcomeEnum::FAILED->value);

        new UpdateTaskStatusAction(
            task: $this->task,
            newStatus: TaskStatusEnum::BLOCKED,
            blockedReason: $reason,
        )->execute();

        return false;
    }

    protected function postProgress(string $text): void
    {
        $plan = $this->task->plan;

        if ($text === '' || $plan === null) {
            return;
        }

        $this->bestEffort(fn () => new PostPlanActivityMessageAction(
            plan: $plan,
            content: $text,
            verb: 'claude_task_progress',
        )->execute());
    }

    protected function announce(DrainResult $result, ?string $blockedReason): void
    {
        $plan = $this->task->plan;
        $owner = $plan?->user;

        if ($plan === null || $owner === null) {
            return;
        }

        $this->bestEffort(fn () => $owner->notify(new PlanProgressNotification(
            $plan,
            $blockedReason === null ? 'Task completed' : 'Task blocked',
            $blockedReason ?? ($result->text !== '' ? $result->text : 'The agent finished this task.'),
            metadata: [
                'task_id' => $this->task->getId(),
                'session_id' => $this->task->get(TaskCustomFieldEnum::CLAUDE_SESSION_ID->value),
                'pull_request_url' => $this->task->get(TaskCustomFieldEnum::CLAUDE_PULL_REQUEST_URL->value),
            ],
            via: ['mail', 'push'],
        )));
    }

    protected function storeCursor(?string $cursor): void
    {
        if ($cursor !== null && $cursor !== '') {
            $this->task->set(TaskCustomFieldEnum::CLAUDE_EVENT_CURSOR->value, $cursor);
        }
    }

    protected function exceededMaxRuntime(): bool
    {
        $startedAt = $this->task->get(TaskCustomFieldEnum::CLAUDE_STARTED_AT->value);

        if (! is_string($startedAt) || $startedAt === '') {
            return false;
        }

        return Carbon::parse($startedAt)->addHours(self::MAX_RUNTIME_HOURS)->isPast();
    }

    protected function isTerminal(): bool
    {
        return TaskStatusEnum::tryFrom($this->task->status)?->isTerminal() ?? false;
    }
}
