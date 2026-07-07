<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum;

/**
 * "You have a message" — the receptionist took a message for a staff member and pings them.
 * In-app + push only (no email template) so it works without any template wiring; the durable
 * copy of the message also lives on the lead as a custom field (see TakeMessageTool).
 */
class ReceptionistMessageNotification extends Notification
{
    public function __construct(
        Lead $lead,
        array $data,
    ) {
        parent::__construct($lead, $data);
        $this->setType(EmailTemplateEnum::BLANK->value);
        $this->setData($data);
        $this->channels = ['push', 'database'];
    }
}
