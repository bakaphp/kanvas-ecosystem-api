<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\FollowUp\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\FollowUp\DataTransferObject\ManualFollowUpPlan;
use Kanvas\Notifications\Notification;

/**
 * In-app notice to the person who set a plan when every attempt went out without a reply. The
 * content is the message itself, no template.
 */
class LeadFollowUpPlanEndedNotification extends Notification
{
    public function __construct(
        protected Lead $lead,
        ManualFollowUpPlan $plan,
        int $attemptsSent,
    ) {
        parent::__construct($lead, [
            'app' => $lead->app,
            'company' => $lead->company,
            'message' => sprintf(
                'Your follow-up plan on lead "%s" is done: all %d %s message(s) went out and the lead did not reply. The stage follow-up applies again.',
                $lead->title,
                $attemptsSent,
                $plan->channel,
            ),
        ]);

        $this->setType('lead-follow-up-plan-ended');
        $this->channels = ['database'];
    }
}
