<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Notifications;

use Kanvas\Guild\Leads\Enums\EmailTemplateEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class LeadReceivedConfirmationNotification extends Notification
{
    public readonly string $reference;

    public function __construct(
        Lead $lead,
        array $data,
    ) {
        parent::__construct($lead, $data);
        $this->reference = (string) ($data['reference'] ?? '');
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::LEAD_RECEIVED_CONFIRMATION->value);
        $this->setSmsTemplateName(EmailTemplateEnum::LEAD_RECEIVED_CONFIRMATION_SMS->value);
        $this->setData($data);
        $this->channels = ['mail'];
    }
}
