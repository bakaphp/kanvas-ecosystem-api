<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Actions;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Guild\Leads\Actions\RecordLeadNoteAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Intelligence\FollowUp\Notifications\LeadFollowUpPlanEndedNotification;
use Kanvas\Users\Models\Users;
use Throwable;

/**
 * Whatever ends a plan (a reply, the last attempt, a cancel, a hand-off, a new plan) comes through
 * here, so the stage config applies again the same way every time: the attempt state is reset and
 * the stage interval restarts now. Only the attempts cap tells the person who set it: the other
 * reasons were their own doing or the lead's.
 */
final class EndLeadFollowUpPlanAction
{
    public function __construct(private readonly Lead $lead, private readonly string $reason)
    {
    }

    public function execute(): ?ManualFollowUpPlan
    {
        $attemptsSent = $this->lead->getFollowUpStateCount();
        $plan = $this->lead->endFollowUpPlan($this->reason, $attemptsSent);
        if ($plan === null) {
            return null;
        }

        $this->lead->resetFollowUpState();

        $this->lead->emitLedgerEvent('lead.follow_up.plan.ended', payload: [
            'stage_id' => $this->lead->pipeline_stage_id,
            'reason' => $this->reason,
            'channel' => $plan->channel,
            'attempts_sent' => $attemptsSent,
            'max_attempts' => $plan->maxAttempts,
            'set_by_users_id' => $plan->setByUsersId,
        ]);

        new RecordLeadNoteAction($this->lead)->execute(
            body: sprintf(
                'Manual follow-up plan ended (%s): %d of %d %s message(s) sent.',
                $this->reason,
                $attemptsSent,
                $plan->maxAttempts,
                $plan->channel,
            ),
            tag: 'follow-up-plan',
            isPublic: false,
        );

        if ($this->reason === ManualFollowUpPlan::REASON_EXHAUSTED) {
            $this->notifySetter($plan, $attemptsSent);
        }

        return $plan;
    }

    private function notifySetter(ManualFollowUpPlan $plan, int $attemptsSent): void
    {
        try {
            /** @var Users $setter */
            $setter = Users::getById($plan->setByUsersId);
            $setter->notify(new LeadFollowUpPlanEndedNotification($this->lead, $plan, $attemptsSent));
        } catch (ModelNotFoundException) {
            return;
        } catch (Throwable $e) {
            report($e);
        }
    }
}
