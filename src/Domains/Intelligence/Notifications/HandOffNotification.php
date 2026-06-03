<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class HandOffNotification extends Notification
{
    public array $channels = ['mail', 'push', 'expo', 'sms', 'database'];

    public function __construct(
        Lead $lead,
        string $templateName,
        array $data
    ) {
        parent::__construct($lead, $data);
        $this->setType('handoff_notification');
        $this->setTemplateName($templateName);
        $this->setData($data);
        $this->setSubject('Lead Handoff Notification - ' . $lead->people->name);
        $this->setPushTemplateName('lead_handoff_push_notification');
        $this->setSmsTemplateName('lead_handoff_sms_notification');
        $this->setDatabaseTemplateName('lead_handoff_sms_notification');
    }

    public function setChannelOnlyPush(): void
    {
        $this->channels = ['push', 'expo'];
    }
}
