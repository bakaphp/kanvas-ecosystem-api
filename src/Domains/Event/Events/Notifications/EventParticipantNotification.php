<?php

declare(strict_types=1);

namespace Kanvas\Event\Events\notifications;

use Kanvas\Event\Events\Enums\EmailTemplateEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class EventParticipantNotification extends Notification
{
    public function __construct(
        Event $event,
        Participant $participant,
        array $data,
    ) {
        parent::__construct($event, $data);
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::PARTICIPANT_NOTIFICATION->value);
        $this->setData($data);
    }
}
