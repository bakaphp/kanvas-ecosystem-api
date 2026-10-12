<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\AgentRuntime\Harness\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\CodingOutcome;
use Kanvas\Intelligence\AgentRuntime\Harness\Enums\CodingOutcomeEnum;
use Kanvas\Intelligence\AgentRuntime\Harness\Models\AgentTaskSession;
use Kanvas\NervousSystem\Plan\Actions\UpdateTaskStatusAction;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Jobs\Traits\AnnouncesPlanOutcome;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Throwable;

/**
 * The only writer of a coding job's final state on its Task and Plan.
 *
 * A job ends in one of five places — the finalizer, the push-approval handler on approve and on
 * reject, and the approval's cancellation or expiry. One writer means the parent waiting on this job
 * is resumed from every ending, through the transition, after the push outcome exists.
 *
 * "Awaiting push" is not an ending. The task parks as WAITING, on the approval's own deadline, and
 * the plan stays open until the approver's answer arrives here as one of the real endings.
 */
class RecordCodingOutcomeAction
{
    use AnnouncesPlanOutcome;

    public function __construct(
        private readonly AgentTaskSession $session,
        private readonly CodingOutcome $outcome,
        /** The finalizer announces in its own, fuller voice; the late endings have only this one. */
        private readonly bool $announce = false,
    ) {
    }

    public function execute(): ?Task
    {
        /** @var Task|null $task */
        $task = $this->session->task;

        if ($task === null) {
            return null;
        }

        if ($task->isTerminal()) {
            // An approval expiring after it was rejected, a cancel racing an approve: the first ending
            // stands, and the second goes on the ledger so it is not simply lost.
            $task->emitLedgerEvent('coding.outcome.after_close', payload: [
                'session_id' => $this->session->getId(),
                'outcome' => $this->outcome->kind->value,
                'task_status' => $task->status,
            ]);

            return $task;
        }

        if ($this->outcome->kind === CodingOutcomeEnum::AWAITING_PUSH) {
            return new UpdateTaskStatusAction(
                task: $task,
                newStatus: TaskStatusEnum::WAITING,
                result: $this->outcome->taskResult($this->session),
                blockedReason: 'Finished; waiting on a human to approve the push.',
                fromSync: true,
                wakeAt: $this->outcome->approval?->expires_at,
                sessionId: $this->session->getId(),
            )->execute();
        }

        $status = $this->outcome->kind->taskStatus();

        $task = new UpdateTaskStatusAction(
            task: $task,
            newStatus: $status,
            result: $this->outcome->taskResult($this->session),
            blockedReason: $status === TaskStatusEnum::BLOCKED ? $this->outcome->reason : null,
            fromSync: true,
            sessionId: $this->session->getId(),
        )->execute();

        $this->closePlan($task);

        if ($this->announce) {
            $this->announceOutcome($task);
        }

        return $task;
    }

    /**
     * `UpdateTaskStatusAction` moves the task and rolls up the percentage but never the plan's own
     * status, and `saveQuietly` fires no model events — so without the explicit broadcast the board
     * shows a finished task under a plan still marked active until someone refreshes.
     */
    private function closePlan(Task $task): void
    {
        /** @var Plan|null $plan */
        $plan = $task->plan;

        if ($plan === null) {
            return;
        }

        $previousStatus = $plan->status;
        $planStatus = $this->outcome->kind->planStatus();
        $plan->status = $planStatus->value;

        if ($planStatus === PlanStatusEnum::DONE) {
            $plan->completed_at = Carbon::now();
        } else {
            $plan->error_message = $this->outcome->reason;
        }

        $plan->saveQuietly();

        try {
            $plan->broadcastChange(PlanChangeTypeEnum::UPDATED, previousStatus: $previousStatus);
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function announceOutcome(Task $task): void
    {
        /** @var Plan|null $plan */
        $plan = $task->plan;

        if ($plan === null) {
            return;
        }

        $title = $this->outcome->kind->headline();
        $body = $title . "\n\n" . $this->outcome->line();

        $this->postToPlanBoard($plan, $body, 'coding_job_result', 'coding-job-alert');
        $this->alsoPostToOriginConversation($plan, $body, 'coding_job_result');
        $this->notifyTheAsker($plan, $title, $body);
    }
}
