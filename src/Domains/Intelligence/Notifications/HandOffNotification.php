<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Enums\HandOffTemplateEnum;
use Kanvas\Notifications\Notification;

class HandOffNotification extends Notification
{
    public array $channels = ['mail', 'push', 'expo', 'sms', 'database'];

    public function __construct(
        Lead $lead,
        string $templateName,
        array $data
    ) {
        parent::__construct($lead, $data);
        $this->setType('blank');
        $this->setTemplateName($templateName);
        $this->setData($data);
        $this->setSubject('Lead Handoff Notification - ' . $lead->people->name);
        $this->setPushTemplateName(HandOffTemplateEnum::PUSH->value);
        $this->setSmsTemplateName(HandOffTemplateEnum::SMS->value);
        $this->setDatabaseTemplateName(HandOffTemplateEnum::SMS->value);
    }

    public function setChannelOnlyPush(): void
    {
        $this->channels = ['push', 'expo'];
    }
}
