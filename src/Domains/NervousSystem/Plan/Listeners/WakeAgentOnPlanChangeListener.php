<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\Intelligence\Agents\Enums\AgentProviderEnum;
use Kanvas\Intelligence\Agents\Models\AgentDeployment;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Jobs\WakeAgentForPlanJob;

class WakeAgentOnPlanChangeListener
{
    /** @var list<string> */
    private const array TERMINAL_TASK_STATUSES = ['done', 'blocked', 'skipped'];

    public function handle(PlanBroadcast $event): void
    {
        if (! $this->shouldWake($event)) {
            return;
        }

        $reason = $this->reasonFor($event);

        $event->plan->emitLedgerEvent(
            'plan.agent.wake_dispatched',
            payload: [
                'agent_id' => $event->plan->agent_id,
                'reason' => $reason,
                'source' => 'listener',
                'change_type' => $event->changeType->value,
            ],
        );

        WakeAgentForPlanJob::dispatch(
            $event->plan,
            $reason,
            $reason === WakeAgentForPlanJob::REASON_TASK_COMPLETED ? $this->completionFact($event) : null,
        );
    }

    protected function shouldWake(PlanBroadcast $event): bool
    {
        // Sync-originated changes already reflect what the agent did on its own board —
        // waking it through the chat path would bounce its own work back at it (loop).
        if ($event->fromSync) {
            return false;
        }

        if ($event->plan->agent_id === null) {
            return false;
        }

        if ($event->changeType === PlanChangeTypeEnum::TASK_STATUS_CHANGED) {
            return $this->shouldWakeOnTaskTerminal($event)
                || $this->shouldWakeOnTaskReactivated($event);
        }

        if (! in_array(
            $event->changeType,
            [PlanChangeTypeEnum::CREATED, PlanChangeTypeEnum::APPROVED, PlanChangeTypeEnum::ASSIGNED],
            true,
        )) {
            return false;
        }

        // Kanban-driven runtime agents (Hermes) get a board card via PushPlanChangeToKanbanListener and work
        // it there — the chat wake would make them do it twice. In-process agents keep the chat wake.
        $deployment = $event->plan->agent?->activeDeployment;
        if ($deployment instanceof AgentDeployment
            && $deployment->isRunning()
            && AgentProviderEnum::forDeployment($deployment)->isHermes()
        ) {
            return false;
        }

        return (bool) ($event->plan->agent?->is_active ?? false);
    }

    /**
     * Close the delegation loop: when a task an agent handed off reaches a terminal state, wake the
     * owner so it can report and follow up.
     *
     * Scoped tightly — only terminal transitions, and only when the assignee is somebody else. An
     * agent that just finished its own task already knows; waking it there would bounce its own work
     * back at it and burn a turn per status write.
     */
    protected function shouldWakeOnTaskTerminal(PlanBroadcast $event): bool
    {
        $task = $event->task;

        if ($task === null || ! in_array($task->status, self::TERMINAL_TASK_STATUSES, true)) {
            return false;
        }

        if ($task->status === $event->previousStatus) {
            return false;
        }

        if ($task->agent_id !== null && $task->agent_id === $event->plan->agent_id) {
            return false;
        }

        return (bool) ($event->plan->agent?->is_active ?? false);
    }

    /**
     * A task moved back OUT of a terminal state is an instruction to do it again, and nothing else in
     * the system acted on it. Resetting five tasks to `pending` looked like an action and was a dead
     * end: no wake fires, so no band dispatches, and the plan sits `active` at 0% indefinitely. The
     * agent then reported that "the runner will pick these up" — there is no such runner.
     *
     * The self-assignee guard from the terminal path is deliberately NOT applied here. There, an agent
     * that just finished its own task already knows. Here the plan's own agent is exactly who has to
     * wake up, because it is the one being asked to redo the work.
     */
    protected function shouldWakeOnTaskReactivated(PlanBroadcast $event): bool
    {
        $task = $event->task;

        if ($task === null || $task->status !== TaskStatusEnum::PENDING->value) {
            return false;
        }

        // Only a genuine reset. A task created pending, or re-saved while already pending, is not a
        // request to run anything.
        if (! in_array((string) $event->previousStatus, self::TERMINAL_TASK_STATUSES, true)) {
            return false;
        }

        return (bool) ($event->plan->agent?->is_active ?? false);
    }

    /**
     * Reopening and completing are opposite instructions, so they must not arrive under the same
     * reason — the completion wake tells the agent to report and close out.
     */
    private function reasonFor(PlanBroadcast $event): string
    {
        return match (true) {
            $event->changeType === PlanChangeTypeEnum::APPROVED => WakeAgentForPlanJob::REASON_APPROVED,
            $this->shouldWakeOnTaskReactivated($event) => WakeAgentForPlanJob::REASON_TASK_REOPENED,
            $event->changeType === PlanChangeTypeEnum::TASK_STATUS_CHANGED => WakeAgentForPlanJob::REASON_TASK_COMPLETED,
            default => WakeAgentForPlanJob::REASON_PLAN_ASSIGNED,
        };
    }

    protected function completionFact(PlanBroadcast $event): string
    {
        $task = $event->task;

        if ($task === null) {
            return 'A task on this plan reached a terminal state.';
        }

        $assignee = $task->agent?->name ?? 'an assignee';
        $fact = sprintf('Task %d ("%s") is now %s, completed by %s.', $task->getId(), (string) $task->title, (string) $task->status, $assignee);

        if (is_string($task->blocked_reason) && $task->blocked_reason !== '') {
            return $fact . ' Reason: ' . $task->blocked_reason;
        }

        $result = is_array($task->result) ? ($task->result['summary'] ?? null) : null;

        return is_string($result) && $result !== '' ? $fact . ' Result: ' . $result : $fact;
    }
}
