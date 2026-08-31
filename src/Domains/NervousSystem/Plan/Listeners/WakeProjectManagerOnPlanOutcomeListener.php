<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * Close the delegation loop at the PLAN level: when a plan a PM handed off finishes, wake the PM.
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
        $target = $this->wakeTarget($event);

        if ($target === null) {
            return;
        }

        [$project, $creator] = $target;
        $plan = $event->plan;

        $plan->emitLedgerEvent(
            'plan.project_manager.wake_dispatched',
            payload: [
                'project_id' => $project->getId(),
                'pm_agent_id' => $project->pmAgent?->getId(),
                'woken_agent_id' => $creator->getId(),
                'plan_status' => $plan->status,
                'previous_status' => $event->previousStatus,
                'change_type' => $event->changeType->value,
            ],
        );

        WakeAgentForProjectJob::dispatch(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            $this->outcomeFact($plan, $event->changeType),
            wakeAgent: $creator,
        );
    }

    /**
     * The project and the agent that should hear about this transition, or null when nobody should.
     *
     * @return array{0: Project, 1: Agent}|null
     */
    private function wakeTarget(PlanBroadcast $event): ?array
    {
        // Every other PlanBroadcast listener drops REJECTED, so if this one does too a human turning
        // a plan down cancels the work and tells nobody.
        $rejected = $event->changeType === PlanChangeTypeEnum::REJECTED;

        if ($event->fromSync || (! $rejected && $event->changeType !== PlanChangeTypeEnum::UPDATED)) {
            return null;
        }

        $plan = $event->plan;

        if (! in_array((string) $plan->status, self::TERMINAL_PLAN_STATUSES, true)) {
            return null;
        }

        // Only the transition. A terminal plan gets re-saved (files, completion, verification) and
        // each save would otherwise wake the PM again.
        if ((string) $plan->status === (string) $event->previousStatus) {
            return null;
        }

        $project = $plan->project;

        // Who ASKED for this work, preferred over the project's current PM: it is right for a plan
        // whose project has since changed hands, and it is the only answer that exists when the
        // creator delegated outside a project.
        /** @var Agent|null $creator */
        $creator = $plan->createdByAgent ?? $project?->pmAgent;

        if ($project === null || $creator === null || ! $creator->is_active) {
            return null;
        }

        // An agent that finished its own plan already knows; waking it there bounces its work at it.
        // A rejection is the exception — a HUMAN made that call, so the assignee has not heard it.
        if (! $rejected && $creator->getId() === $plan->agent_id) {
            return null;
        }

        return [$project, $creator];
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
