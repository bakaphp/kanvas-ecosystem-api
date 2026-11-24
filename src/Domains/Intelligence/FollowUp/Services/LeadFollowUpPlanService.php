<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Services;

use Kanvas\Guild\Leads\Models\Lead;

final class LeadFollowUpPlanService
{
    public function __construct(private readonly LeadFollowUpEligibilityService $eligibility = new LeadFollowUpEligibilityService())
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function status(Lead $lead): ?array
    {
        $active = $lead->getFollowUpPlan();
        $plan = $active ?? $lead->getLastFollowUpPlan();
        if ($plan === null) {
            return null;
        }

        $attemptsSent = $active !== null ? $lead->getFollowUpStateCount() : (int) $plan->attemptsSent;

        return [
            'is_active' => $active !== null,
            'channel' => $plan->channel,
            'message' => $plan->body,
            'interval_minutes' => $plan->intervalMinutes,
            'max_attempts' => $plan->maxAttempts,
            'attempts_sent' => $attemptsSent,
            'start_at' => $plan->startAt->toIso8601String(),
            'stop_on_reply' => $plan->stopOnReply,
            'respect_work_hours' => $plan->respectWorkHours,
            'next_due_at' => $active !== null ? $plan->nextDueAt($lead->getFollowUpLastAt())->toIso8601String() : null,
            'paused_reason' => $active !== null ? $this->pausedReason($lead) : null,
            'done_at' => $plan->doneAt?->toIso8601String(),
            'done_reason' => $plan->doneReason,
            'set_at' => $plan->setAt->toIso8601String(),
            'set_by_users_id' => $plan->setByUsersId,
        ];
    }

    /**
     * The gates a plan keeps, as the person would name them. Null means it runs on schedule.
     */
    public function pausedReason(Lead $lead): ?string
    {
        if ($this->eligibility->aiFollowUpDisabled($lead)) {
            return 'lead_follow_up_disabled';
        }

        if (! $lead->hasOpenLeadStatus()) {
            return 'lead_not_active';
        }

        return $this->eligibility->pausedReason($lead);
    }
}
