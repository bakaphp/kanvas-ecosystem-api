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
        $productId = null;
        if (isset($payload['custom_fields'])) {
            foreach ($payload['custom_fields'] as $customField) {
                if ($customField['name'] === 'product_id') {
                    $productId = $customField['data'];
                }
            }
        }

        if ($productId) {
            $payload['product'] = $sendLeadEmailsAction->getProduct($productId);
        }
        $sendLeadEmailsAction->execute($payload, $user);
    }
}
