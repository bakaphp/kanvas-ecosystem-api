<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Listeners;

use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Events\PlanBroadcast;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Project\Jobs\WakeAgentForProjectJob;
use Kanvas\NervousSystem\Project\Models\Project;

/**
 * Close the delegation loop at the PLAN level: when a plan a PM handed off finishes, wake the PM.
 *
 * `WakeAgentOnPlanChangeListener` covers the task level, but it wakes the plan's OWN agent and skips a
 * task whose assignee is that agent. Delegating a whole PLAN — the unit the PM is instructed to use —
 * makes the worker the plan's agent, so its tasks are its own and every task wake is suppressed as
 * self-notification, leaving nobody watching.
 *
 * The project heartbeat does not cover it either: `needsAttention()` looks for blocked plans and
 * stalled tasks, so a plan that finished CLEANLY is exactly the case it decides needs no attention.
 */
class WakeProjectManagerOnPlanOutcomeListener
{
    /** @var list<string> */
    private const array TERMINAL_PLAN_STATUSES = ['done', 'blocked', 'failed', 'cancelled'];

    public function handle(PlanBroadcast $event): void
    {
        $project = $this->projectAwaitingOutcome($event);

        if ($project === null) {
            return;
        }

        $plan = $event->plan;

        $plan->emitLedgerEvent(
            'plan.project_manager.wake_dispatched',
            payload: [
                'project_id' => $project->getId(),
                'pm_agent_id' => $project->pmAgent?->getId(),
                'created_by_agent_id' => $plan->created_by_agent_id,
                'plan_status' => $plan->status,
                'previous_status' => $event->previousStatus,
            ],
        );

        WakeAgentForProjectJob::dispatch(
            $project,
            WakeAgentForProjectJob::REASON_PLAN_OUTCOME,
            $this->outcomeFact($plan),
        );
    }

    /**
     * The project whose PM should hear about this transition, or null when nobody should.
     */
    private function projectAwaitingOutcome(PlanBroadcast $event): ?Project
    {
        if ($event->fromSync || $event->changeType !== PlanChangeTypeEnum::UPDATED) {
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
        $creator = $plan->createdByAgent ?? $project?->pmAgent;

        if ($project === null || $creator === null || ! $creator->is_active) {
            return null;
        }

        // An agent that finished its own plan already knows; waking it there bounces its work at it.
        return $creator->getId() === $plan->agent_id ? null : $project;
    }

    private function outcomeFact(Plan $plan): string
    {
        $worker = $plan->agent?->name ?? 'an assignee';

        $fact = sprintf(
            'Plan %d ("%s") is now %s, worked by %s.',
            $plan->getId(),
            (string) $plan->title,
            (string) $plan->status,
            $worker,
        );

        return $fact . ' Read it with read_plan_activity before you report anything about it — the '
            . 'result and any blocker are recorded on its tasks.';
    }
}
