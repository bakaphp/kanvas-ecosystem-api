<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Plan\Actions;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Exceptions\ValidationException;
use Kanvas\NervousSystem\Plan\Enums\PlanChangeTypeEnum;
use Kanvas\NervousSystem\Plan\Enums\PlanStatusEnum;
use Kanvas\NervousSystem\Plan\Models\Plan;
use Kanvas\Users\Models\Users;

class ApprovePlanAction
{
    public function __construct(
        protected readonly Plan $plan,
        protected readonly Users $reviewer,
        protected readonly bool $approved,
        protected readonly ?string $reviewOutcome = null,
    ) {
    }

    public function execute(): Plan
    {
        if ($this->plan->status !== PlanStatusEnum::AWAITING_APPROVAL->value) {
            throw new ValidationException(
                'Only plans in awaiting_approval status can be approved or rejected.',
            );
        }

        return DB::connection('intelligence')->transaction(function (): Plan {
            $reviewerId = $this->reviewer->getId();

            $this->plan->approved_by_user_id = $reviewerId;
            $this->plan->approved_at = Carbon::now();
            $this->plan->review_outcome = $this->reviewOutcome;

            if ($this->approved) {
                $this->plan->status = PlanStatusEnum::ACTIVE->value;
                $this->plan->started_at = $this->plan->started_at ?? Carbon::now();
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
}
