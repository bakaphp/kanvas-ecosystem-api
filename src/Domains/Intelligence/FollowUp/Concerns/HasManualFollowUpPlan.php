<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Concerns;

use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;

/**
 * The only reader and writer of the `follow_up_plan` / `follow_up_plan_last` custom fields. The
 * consumer model must `use HasCustomFields`. Attempts are not here: a plan counts them in
 * `follow_up_state`, like a stage does (HasFollowUpState).
 */
trait HasManualFollowUpPlan
{
    public function getFollowUpPlan(): ?ManualFollowUpPlan
    {
        return $this->followUpPlanIn(ManualFollowUpPlan::ACTIVE_FIELD);
    }

    public function getLastFollowUpPlan(): ?ManualFollowUpPlan
    {
        return $this->followUpPlanIn(ManualFollowUpPlan::ENDED_FIELD);
    }

    public function setFollowUpPlan(ManualFollowUpPlan $plan): void
    {
        $this->set(ManualFollowUpPlan::ACTIVE_FIELD, $plan->toArray());
    }

    /**
     * Moves the active plan to the ended slot. Null when none was running.
     */
    public function endFollowUpPlan(string $reason, int $attemptsSent): ?ManualFollowUpPlan
    {
        $plan = $this->getFollowUpPlan();
        if ($plan === null) {
            return null;
        }

        $ended = $plan->ended($reason, $attemptsSent);
        $this->set(ManualFollowUpPlan::ENDED_FIELD, $ended->toArray());
        $this->del(ManualFollowUpPlan::ACTIVE_FIELD);

        return $ended;
    }

    private function followUpPlanIn(string $field): ?ManualFollowUpPlan
    {
        $raw = $this->get($field);

        return is_array($raw) ? ManualFollowUpPlan::fromArray($raw) : null;
    }
}
