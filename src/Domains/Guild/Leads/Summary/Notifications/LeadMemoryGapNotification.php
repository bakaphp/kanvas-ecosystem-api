<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Summary\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Notification;

/**
 * Tells the lead's owner the customer-facing agent answered without part of the history: neither its
 * window nor the summary covered it. In-app only, the content is the message itself, no template.
 */
class LeadMemoryGapNotification extends Notification
{
    public function __construct(
        protected Lead $lead,
        string $from,
        string $to,
    ) {
        parent::__construct($lead, [
            'app' => $lead->app,
            'company' => $lead->company,
            'message' => sprintf(
                'The agent on lead "%s" replied without the messages from %s to %s in view: they are past its history window and not summarized yet. It was told not to confirm prices, appointments or promises it cannot see. Catch-up is queued.',
                $lead->title,
                $from,
                $to,
            ),
        ]);

        $this->setType('lead-memory-gap');
        $this->channels = ['database'];
    }
}
