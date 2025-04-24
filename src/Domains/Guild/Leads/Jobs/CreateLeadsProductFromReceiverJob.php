<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Kanvas\Guild\Leads\Actions\SendLeadEmailsAction;
use Kanvas\Guild\Leads\Enums\LeadNotificationModeEnum;
use Kanvas\Guild\Leads\Models\Lead;

class CreateLeadsProductFromReceiverJob extends CreateLeadsFromReceiverJob
{
    protected function sendLeadEmails(string $emailTemplate, array $users, Lead $lead, array $payload, LeadNotificationModeEnum $notificationMode = LeadNotificationModeEnum::NOTIFY_ALL): void
    {
        $sendLeadEmailsAction = new SendLeadEmailsAction($lead, $emailTemplate);
        $fieldMaps = $this->mapCustomFields($payload['custom_fields']);
        if (isset($fieldMaps['product_id'])) {
            $payload['product'] = $sendLeadEmailsAction->getProduct($fieldMaps['product_id']);
        }
        $payload['field_maps'] = $fieldMaps;
        $sendLeadEmailsAction->execute($payload, $users, $notificationMode);
    }
}
