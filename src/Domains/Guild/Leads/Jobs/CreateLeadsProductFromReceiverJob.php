<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Leads\Actions\SendLeadEmailsAction;
use Kanvas\Guild\Leads\Models\Lead;

class CreateLeadsProductFromReceiverJob extends CreateLeadsFromReceiverJob
{
    protected function sendLeadEmails(string $emailTemplate, Model $user, Lead $lead, array $payload): void
    {
        $sendLeadEmailsAction = new SendLeadEmailsAction($lead, $emailTemplate);
        $fieldMaps = $this->mapCustomFields($payload['custom_fields']);
        if (isset($fieldMaps['product_id'])) {
            $payload['product'] = $sendLeadEmailsAction->getProduct($fieldMaps['product_id']);
        }
        $payload['field_maps'] = $fieldMaps;
        $sendLeadEmailsAction->execute($payload, $user);
    }

    protected function mapCustomFields(array $customFields): array
    {
        $fieldMaps = [];
        foreach ($customFields as $customField) {
            $fieldMaps[$customField['name']] = $customField['data'];
        }
        return $fieldMaps;
    }
}
