<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Carbon\CarbonInterface;
use Carbon\CarbonInterval;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\IntelligenceModeEnum;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Intelligence\FollowUp\Enums\FollowUpTypeEnum;
use Kanvas\Intelligence\FollowUp\Services\LeadFollowUpEligibilityService;
use Kanvas\Users\Models\Users;

/**
 * A plan replaces the stage default for this lead while it runs, so attempts restart from zero. The
 * lead's follow-up toggle is the master switch and a plan never runs with it off: setting one turns
 * it on, which is why the tool asks the person first when it finds it off.
 */
final class SetLeadFollowUpPlanAction
{
    public function __construct(
        private readonly Lead $lead,
        private readonly ManualFollowUpPlan $plan,
        private readonly Users $setBy
    ) {
    }

    public function execute(): ManualFollowUpPlan
    {
        new EndLeadFollowUpPlanAction($this->lead, ManualFollowUpPlan::REASON_REPLACED)->execute();

        $this->lead->resetFollowUpState();
        $this->lead->setFollowUpPlan($this->plan);

        // Absent `ai_follow_up` already counts as on for the engine, so only an explicit off is a change.
        $followUpTurnedOn = new LeadFollowUpEligibilityService()->aiFollowUpDisabled($this->lead);
        if ($followUpTurnedOn) {
            $this->lead->set(IntelligenceModeEnum::AI_FOLLOW_UP->value, FollowUpTypeEnum::LEAD_FOLLOW_UP->value);
        }

        $this->lead->emitLedgerEvent('lead.follow_up.plan.set', payload: [
            'stage_id' => $this->lead->pipeline_stage_id,
            'channel' => $this->plan->channel,
            'interval_minutes' => $this->plan->intervalMinutes,
            'max_attempts' => $this->plan->maxAttempts,
            'start_at' => $this->plan->startAt->toIso8601String(),
            'stop_on_reply' => $this->plan->stopOnReply,
            'set_by_users_id' => $this->setBy->getId(),
            'follow_up_turned_on' => $followUpTurnedOn,
        ]);

        new RecordLeadNoteAction($this->lead)->execute(
            body: sprintf(
                'Manual follow-up plan set by %s: %s every %s, up to %d attempts, starting %s%s. Message: "%s"',
                $this->setBy->displayname,
                $this->plan->channel,
                CarbonInterval::minutes($this->plan->intervalMinutes)->cascade()->forHumans(['syntax' => CarbonInterface::DIFF_ABSOLUTE]),
                $this->plan->maxAttempts,
                $this->plan->startAt->toDayDateTimeString(),
                $this->plan->stopOnReply ? ', stops when the lead replies' : '',
                $this->plan->body,
            ),
            tag: 'follow-up-plan',
            actingUser: $this->setBy,
            isPublic: false,
        );

        return $this->plan;
    }
}
