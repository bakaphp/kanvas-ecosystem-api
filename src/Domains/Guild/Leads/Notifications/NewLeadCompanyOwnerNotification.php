<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Notifications;

use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Enums\EmailTemplateEnum;
use Kanvas\Notifications\Notification;
use Kanvas\Templates\Enums\EmailTemplateEnum as EnumsEmailTemplateEnum;

class NewLeadCompanyOwnerNotification extends Notification
{
    public function __construct(
        Lead $lead,
        array $data,
    ) {
        parent::__construct($lead, $data);
        $this->setType(EnumsEmailTemplateEnum::BLANK->value);
        $this->setTemplateName(EmailTemplateEnum::NEW_LEAD_COMPANY_ADMIN->value);
        $this->setData($data);

        if (! $this->app->get(ConfigurationEnum::SEND_NEW_LEAD_TO_OWNER_NOTIFICATION->value)) {
            $this->channels = [];
        }
    }
}
