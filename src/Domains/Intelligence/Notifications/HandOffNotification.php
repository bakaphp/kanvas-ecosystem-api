<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class HandOffNotification extends Notification
{
    public array $channels = [
        'email',
        OneSignalNotificationChannel::class,
    ];

    public function __construct(
        Lead $lead,
        string $templateName,
        array $data
    ) {
        parent::__construct($lead, $data);
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName($templateName);
        $this->setData($data);
    }
}
