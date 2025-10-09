<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Notifications;

use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Channels\OneSignalNotificationChannel;
use Kanvas\Notifications\Channels\TwilioSmsChannel;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;
use NotificationChannels\Expo\ExpoChannel;

class HandOffNotification extends Notification
{
    public array $channels = [
        'mail',
        OneSignalNotificationChannel::class,
        ExpoChannel::class,
        TwilioSmsChannel::class,
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
        $this->setSubject('Lead Handoff Notification');
        $this->setPushTemplateName('lead_handoff_push_notification');
        $this->setSmsTemplateName('lead_handoff_sms_notification');
    }
}
