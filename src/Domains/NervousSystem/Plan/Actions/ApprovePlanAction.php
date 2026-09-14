<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Enums\TaskStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\NervousSystem\Plan\Models\Task;
use Kanvas\Users\Models\Users;

class ApprovePlanAction
{
    public function __construct(
        protected readonly Plan $plan,
        protected readonly Users $reviewer,
        protected readonly bool $approved,
        protected readonly ?string $reviewOutcome = null,
        /** Deliberate opt-in for a flow where the requester signing its own work off is the design. */
        protected readonly bool $allowSelfApproval = false,
    ) {
    }

    public function execute(): Plan
    {
        if ($this->plan->status !== PlanStatusEnum::AWAITING_APPROVAL->value) {
            throw new ValidationException(
                'Only plans in awaiting_approval status can be approved or rejected.',
            );
        }

        if (! $this->allowSelfApproval && $this->isSelfApproval() && $this->hasHumanInTheLoop()) {
            throw new ValidationException(
                'This plan is waiting on a person, and you cannot approve your own request. Ask the '
                . 'human who opened it — approval has to come from someone other than the agent that '
                . 'asked for it.',
            );
        }

        return DB::connection('intelligence')->transaction(function (): Plan {
            $reviewerId = $this->reviewer->getId();

            $this->plan->approved_by_user_id = $reviewerId;
            $this->plan->approved_at = Carbon::now();
            $this->plan->review_outcome = $this->reviewOutcome;

            $released = 0;

            if ($this->approved) {
                $this->plan->status = PlanStatusEnum::ACTIVE->value;
                $this->plan->started_at = $this->plan->started_at ?? Carbon::now();
                $released = $this->releaseBlockedTasks();
            } else {
                $this->plan->status = PlanStatusEnum::CANCELLED->value;
                $this->plan->completed_at = Carbon::now();
            }

            $this->plan->saveOrFail();

            $this->plan->emitLedgerEvent(
                eventType: $this->approved ? 'plan.approved' : 'plan.rejected',
                payload: [
                    'review_outcome' => $this->reviewOutcome,
                    'reviewer_id' => $reviewerId,
                    'released_tasks' => $released,
                ],
                actorType: 'User',
                actorId: $reviewerId,
            );

            $this->plan->broadcastChange(
                changeType: $this->approved
                    ? PlanChangeTypeEnum::APPROVED
                    : PlanChangeTypeEnum::REJECTED,
            );

            // Rejection is a terminal transition (→ cancelled). Approve
            // moves to active, which isn't terminal — no swarm milestone.
            if (! $this->approved) {
                new PostPlanCompletionToSwarmAction($this->plan)->execute();
            }

            return $this->plan->fresh() ?? $this->plan;
        });
    }

    /**
     * Put the work that was parked on this approval back in play.
     *
     * The state has to be right before the wake, not after: PlanContinuationAction computes its
     * verdict from task state BEFORE the agent's turn, so a prompt telling it to resume cannot lift
     * a task still marked blocked. Every blocked task, not a timestamp window — one blocked for an
     * unrelated reason is re-blocked by its own agent next turn, with the real reason. Written
     * quietly because the APPROVED broadcast already wakes the agent.
     */
    private function releaseBlockedTasks(): int
    {
        return Task::query()
            ->where('plan_id', $this->plan->getId())
            ->where('status', TaskStatusEnum::BLOCKED->value)
            ->where('is_deleted', 0)
            ->update([
                'status' => TaskStatusEnum::PENDING->value,
                'blocked_reason' => null,
            ]);
    }

    /**
     * The agent that asked for approval signing it off itself.
     *
     * Both the requester and the current assignee count: on plan 25667 the PM asked a human for a
     * $500 approval and granted it as itself twenty-five seconds later, because the mention it sent
     * reached nobody and the question looked unanswered.
     */
    private function isSelfApproval(): bool
    {
        $reviewerId = $this->reviewer->getId();

        foreach ([$this->plan->createdByAgent, $this->plan->agent] as $agent) {
            if ($agent?->user?->getId() === $reviewerId) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a person could approve this instead.
     *
     * `origin_users_id` is the field built for exactly this question — the human who was actually
     * talking when the plan was opened — because `users_id` on agent-created work is another agent's
     * user and is never a route to a person. `assigned_users_id` is only ever set by handing the plan
     * to a human. With neither, there is nobody to ask: refusing would wedge the plan forever, so an
     * autonomous flow is allowed to sign off its own work.
     */
    private function hasHumanInTheLoop(): bool
    {
        return $this->plan->origin_users_id !== null
            || $this->plan->assigned_users_id !== null;
    }
}
