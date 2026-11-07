<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Notifications\Notification;

class LeadFollowUpPlanEndedNotification extends Notification
{
    public function __construct(protected Lead $lead, ManualFollowUpPlan $plan, int $attemptsSent)
    {
        parent::__construct($lead, [
            'app' => $lead->app,
            'company' => $lead->company,
            'message' => sprintf(
                'Your follow-up plan on lead "%s" reached its limit of %d %s attempt(s). The stage follow-up applies again.',
                $lead->title,
                $attemptsSent,
                $plan->channel,
            ),
        ]);

        $this->setType('lead-follow-up-plan-ended');
        $this->channels = ['database'];
    }
}
