<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\ResumeParentTaskJob;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Plan\Models\TaskChildOutcome;

/**
 * A delegated task finished: write the outcome where the parent will find it, bring the parent out
 * of its wait, and queue the wake that hands it the result.
 *
 * Runs inside the transition's transaction, so the parent row is locked while its status moves and
 * the wake is dispatched only once that commits. Idempotent on the attempt: a child whose terminal
 * write is replayed finds its row already there and queues nothing.
 *
 * A parent that is already closed is never reopened by a late result — the outcome is recorded and
 * announced on the ledger, which is where a human looks for work that landed after the fact.
 */
class RecordChildOutcomeAction
{
    public function __construct(
        private readonly Task $child,
        private readonly ?int $sessionId = null,
    ) {
    }

    public function execute(): ?TaskChildOutcome
    {
        if ($this->child->parent_task_id === null || ! $this->child->isTerminal()) {
            return null;
        }

        /** @var Task|null $parent */
        $parent = Task::query()
            ->whereKey($this->child->parent_task_id)
            ->where('is_deleted', 0)
            ->lockForUpdate()
            ->first();

        if ($parent === null) {
            return null;
        }

        $outcome = TaskChildOutcome::query()->firstOrCreate(
            [
                'child_task_id' => $this->child->getId(),
                'attempt_id' => $this->attemptId(),
            ],
            [
                'apps_id' => $this->child->apps_id,
                'companies_id' => $this->child->companies_id,
                'parent_task_id' => $parent->getId(),
                'revision' => $this->child->parent_revision,
                'status' => $this->child->status,
                'summary' => $this->summary(),
                'agent_task_session_id' => $this->sessionId,
                'created_at' => Carbon::now(),
            ],
        );

        if (! $outcome->wasRecentlyCreated) {
            return $outcome;
        }

        if ($parent->isTerminal()) {
            $outcome->delivered_at = Carbon::now();
            $outcome->saveOrFail();

            $parent->emitLedgerEvent('plan.task.child_outcome_after_close', payload: [
                'child_task_id' => $this->child->getId(),
                'outcome_id' => $outcome->getId(),
                'child_status' => $this->child->status,
                'parent_status' => $parent->status,
            ]);

            return $outcome;
        }

        if ($parent->status === TaskStatusEnum::WAITING->value) {
            new UpdateTaskStatusAction(
                task: $parent,
                newStatus: TaskStatusEnum::IN_PROGRESS,
                fromSync: true,
            )->execute();
        }

        ResumeParentTaskJob::dispatch($outcome)->afterCommit();

        return $outcome;
    }

    /**
     * A task written straight to a terminal state by an older path may never have entered
     * in_progress through the transition and so carries no attempt. One is minted here so the row
     * still has an identity — and stored, so a replay of this same ending finds it.
     */
    private function attemptId(): string
    {
        if ($this->child->current_attempt_id === null) {
            $this->child->current_attempt_id = Str::uuid()->toString();
            $this->child->saveQuietly();
        }

        return $this->child->current_attempt_id;
    }

    private function summary(): ?string
    {
        $result = is_array($this->child->result) ? $this->child->result : [];
        $summary = $result['worker_summary'] ?? $result['summary'] ?? $this->child->blocked_reason;

        return is_string($summary) && trim($summary) !== '' ? Str::limit(trim($summary), 4000) : null;
    }
}
