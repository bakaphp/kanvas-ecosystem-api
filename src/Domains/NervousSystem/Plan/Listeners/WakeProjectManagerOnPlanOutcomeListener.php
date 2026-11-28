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
use Kanvas\NervousSystem\Plan\Services\BlockOutcomeDeliveryService;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;
use Throwable;

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

        if (! $finishedEveryTask && ! $this->isPlanOutcome($event)) {
            return;
        }

        $target = $this->wakeTarget($event);

        if ($target === null) {
            return;
        }

        [$project, $creator] = $target;
        $plan = $event->plan;
        $handedOff = $creator->is($plan->createdByAgent) && $plan->wasHandedOffByItsCreator();

        // A synced plan change is normally a mirror with nobody to tell. Work an agent handed off is the
        // exception: its creator is a different agent that is waiting to hear, and most workers record
        // their outcome as a sync.
        if (! $finishedEveryTask && $event->fromSync && ! $handedOff) {
            return;
        }

        $blockCycle = null;

        if ($finishedEveryTask) {
            $plan->rememberFinishWasReported();

            // Only the agent that asked for the plan answers in the person's conversation; anyone else's
            // wake leaves the done alert to say it there.
            if ($creator->is($plan->createdByAgent)) {
                $plan->rememberAnswerIsComing();
            }
        } elseif ($handedOff && $plan->status === PlanStatusEnum::DONE->value) {
            // Closed directly, with no last task to report: the creator answers in the conversation, so
            // the done alert must not say it there as well.
            $plan->rememberAnswerIsComing();
        } elseif ($handedOff && $plan->status === PlanStatusEnum::BLOCKED->value) {
            // Reserved before the wake is sent: the blocked alert is queued for 45 seconds from now and
            // the creator may still be thinking then.
            $delivery = new BlockOutcomeDeliveryService($plan);
            $blockCycle = $delivery->currentCycleId() ?? $delivery->startCycle();
            $delivery->reserveForCreator($blockCycle);
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

        try {
            WakeAgentForProjectJob::dispatch(
                $project,
                WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
                $finishedEveryTask
                    ? $this->everyTaskFinishedFact($plan)
                    : $this->outcomeFact($plan, $event->changeType),
                wakeAgent: $creator,
                reportsOn: $finishedEveryTask ? $plan : null,
                aboutPlan: $plan,
                blockOutcomeId: $blockCycle,
            );
        } catch (Throwable $e) {
            // The wake never left, so the fallback already queued must be free to speak.
            if ($blockCycle !== null) {
                new BlockOutcomeDeliveryService($plan)->releaseReservation($blockCycle);
            }

            throw $e;
        }
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
        $steps = $plan->tasks()->whereNull('parent_task_id')->get();
        $learn = $steps->first(fn (Task $task): bool => FactoryStage::isLearnStep($task));

        // The whole answer is on the task: read_plan_activity cuts the middle out of a long result, and
        // the improvements become the tickets word for word.
        if ($learn !== null) {
            return ' This was the app\'s Learn stage. Right away, without asking anyone: read its whole answer, the '
                . 'result of task ' . $learn->getId() . ', with get_nervous_system_task, then start each "'
                . trim(FactoryStage::IMPROVEMENT_HEADING) . '" it lists with start_factory_ticket, in its order and '
                . 'one at a time, numbered after the app\'s last ticket, with the improvement\'s whole text as the '
                . 'ticket and has_screens from its "Has screens" line; after the last, call start_factory_release. '
                . 'Pass every "## Reading" and "Needs a person" line to the person. "No improvement" ends the round: '
                . 'say so and start nothing.';
        }

        if (! $steps->contains(fn (Task $task): bool => FactoryStage::landsOnIntegration($task->fullText()))) {
            return '';
        }

        return ' This factory stage landed on `' . FactoryStage::INTEGRATION_BRANCH . '`. Right away, without asking '
            . 'anyone: start the next ticket of the approved plan with start_factory_ticket, or, if this was the '
            . 'last one, call start_factory_release with every ticket of the stage in notes.';
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

        return sprintf(
            'Plan %d ("%s") is now %s, worked by %s.',
            $plan->getId(),
            (string) $plan->title,
            (string) $plan->status,
            $plan->agent?->name ?? 'an assignee',
        ) . ' Read it with read_plan_activity before you report anything about it — the result and any '
            . 'blocker are recorded on its tasks.';
    }
}
