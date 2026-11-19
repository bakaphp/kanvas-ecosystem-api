<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\Intelligence\AgentRuntime\Harness\DataTransferObject\FactoryStage;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * Close the delegation loop at the PLAN level: when a plan a PM handed off finishes, wake the PM —
 * and when its last task finishes but the worker leaves the plan open, wake it then.
 *
 * Nothing else covers it. `WakeAgentOnPlanChangeListener` wakes the plan's OWN agent and skips tasks
 * assigned to it — and delegating a whole plan makes the worker that agent, so every task wake is
 * suppressed as self-notification. The heartbeat's `needsAttention()` looks for blocked plans and
 * stalled tasks, so a plan that finished CLEANLY is precisely what it decides needs no attention.
 */
class WakeProjectManagerOnPlanOutcomeListener
{
    /** @var list<string> */
    private const array TERMINAL_PLAN_STATUSES = ['done', 'blocked', 'failed', 'cancelled'];

    public function handle(PlanBroadcast $event): void
    {
        // A worker records its own result as a sync (RunTaskWorkerJob), so that is how most delegated
        // tasks finish. The wake goes to the creator, never the agent that wrote the change, so a sync
        // cannot loop it back on itself here.
        $finishedEveryTask = $this->finishedEveryTask($event);

        if (! $finishedEveryTask && ($event->fromSync || ! $this->isPlanOutcome($event))) {
            return;
        }

        $target = $this->wakeTarget($event);

        if ($target === null) {
            return;
        }

        [$project, $creator] = $target;
        $plan = $event->plan;

        if ($finishedEveryTask) {
            $plan->rememberFinishWasReported();

            // Only the agent that asked for the plan answers in the person's conversation; anyone else's
            // wake leaves the done alert to say it there.
            if ($creator->is($plan->createdByAgent)) {
                $plan->rememberAnswerIsComing();
            }
        }

        $plan->emitLedgerEvent(
            'plan.project_manager.wake_dispatched',
            payload: [
                'project_id' => $project->getId(),
                'pm_agent_id' => $project->pmAgent?->getId(),
                'woken_agent_id' => $creator->getId(),
                'plan_status' => $plan->status,
                'previous_status' => $event->previousStatus,
                'change_type' => $event->changeType->value,
                'task_id' => $finishedEveryTask ? $event->task?->getId() : null,
            ],
        );

        WakeAgentForProjectJob::dispatch(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            $finishedEveryTask
                ? $this->everyTaskFinishedFact($plan)
                : $this->outcomeFact($plan, $event->changeType),
            wakeAgent: $creator,
            reportsOn: $finishedEveryTask ? $plan : null,
        );
    }

    private function isPlanOutcome(PlanBroadcast $event): bool
    {
        // Every other PlanBroadcast listener drops REJECTED, so if this one does too a human turning
        // a plan down cancels the work and tells nobody.
        $rejected = $event->changeType === PlanChangeTypeEnum::REJECTED;

        if (! $rejected && $event->changeType !== PlanChangeTypeEnum::UPDATED) {
            return false;
        }

        $plan = $event->plan;

        if (! in_array((string) $plan->status, self::TERMINAL_PLAN_STATUSES, true)) {
            return false;
        }

        // Only the transition. A terminal plan gets re-saved (files, completion, verification) and
        // each save would otherwise wake the PM again.
        if ((string) $plan->status === (string) $event->previousStatus) {
            return false;
        }

        // A failed run retired once the work moved past it (`Plan::retireFailedAttempt()`): the failure
        // already woke whoever was waiting on it.
        if ($event->previousStatus === PlanStatusEnum::FAILED->value && $plan->status === PlanStatusEnum::CANCELLED->value) {
            return false;
        }

        return ! ($plan->status === PlanStatusEnum::DONE->value && $plan->finishWasReported());
    }

    /**
     * The plan's last open task just finished and the plan is still open.
     *
     * Nothing closes a plan by itself, and when the plan's own agent finished that task the task-level
     * wake skips it as self-notification — so a worker that marks its task done and stops there left
     * the agent that asked for the work, and the person behind it, waiting on a result already in.
     */
    private function finishedEveryTask(PlanBroadcast $event): bool
    {
        $task = $event->task;

        if ($event->changeType !== PlanChangeTypeEnum::TASK_STATUS_CHANGED || $task === null) {
            return false;
        }

        if (! in_array((string) $task->status, TaskStatusEnum::completedStatusValues(), true)
            || (string) $task->status === (string) $event->previousStatus
        ) {
            return false;
        }

        $plan = $event->plan;

        return ! in_array((string) $plan->status, self::TERMINAL_PLAN_STATUSES, true)
            && $plan->everyTaskFinished();
    }

    /**
     * Who should hear about this plan: the agent that asked for it, preferred over the project's
     * current PM. That is right for a plan whose project has since changed hands, and it is the only
     * answer that exists when the creator delegated outside a project.
     *
     * @return array{0: Project, 1: Agent}|null
     */
    private function wakeTarget(PlanBroadcast $event): ?array
    {
        $plan = $event->plan;
        $project = $plan->project;

        /** @var Agent|null $creator */
        $creator = $plan->createdByAgent ?? $project?->pmAgent;

        if ($project === null || $creator === null || ! $creator->is_active) {
            return null;
        }

        // An agent that finished its own plan already knows; waking it there bounces its work at it.
        // A rejection is the exception — a HUMAN made that call, so the assignee has not heard it.
        if ($event->changeType !== PlanChangeTypeEnum::REJECTED && $creator->getId() === $plan->agent_id) {
            return null;
        }

        return [$project, $creator];
    }

    private function everyTaskFinishedFact(Plan $plan): string
    {
        return sprintf(
            'Every task on plan %d ("%s") is finished, worked by %s, and the plan is still open. Read it with '
                . 'read_plan_activity, then answer the person who asked for this work: say it is done, what '
                . 'came of it, and what you need from them, if anything. Your reply is delivered into the '
                . 'conversation they asked in. Then move the plan to done with update_nervous_system_plan, '
                . 'unless the work continues in it.',
            $plan->getId(),
            (string) $plan->title,
            $plan->agent?->name ?? 'its assignee',
        ) . $this->nextFactoryStage($plan);
    }

    /**
     * Nobody else starts what follows a landed ticket, so the fact has to say what comes next.
     */
    private function nextFactoryStage(Plan $plan): string
    {
        $landed = $plan->tasks()->whereNull('parent_task_id')->get()->contains(
            fn (Task $task): bool => FactoryStage::landsOnIntegration($task->fullText())
        );

        if (! $landed) {
            return '';
        }

        return ' ' . FactoryStage::whatFollows($plan);
    }

    private function outcomeFact(Plan $plan, PlanChangeTypeEnum $changeType): string
    {
        if ($changeType === PlanChangeTypeEnum::REJECTED) {
            return sprintf(
                'Plan %d ("%s") was REJECTED by a human and is now %s — the work is stopped. Read it with '
                    . 'read_plan_activity to see the reason given, then decide whether to revise and re-submit '
                    . 'it or drop it. Do not simply re-create the same plan.',
                $plan->getId(),
                (string) $plan->title,
                (string) $plan->status,
            );
        }

        return trim(sprintf(
            'Plan %d ("%s") is now %s, worked by %s.',
            $plan->getId(),
            (string) $plan->title,
            (string) $plan->status,
            $plan->agent?->name ?? 'an assignee',
        ) . ' Read it with read_plan_activity before you report anything about it — the result and any '
            . 'blocker are recorded on its tasks. ' . FactoryStage::afterBackendGap($plan));
    }
}
