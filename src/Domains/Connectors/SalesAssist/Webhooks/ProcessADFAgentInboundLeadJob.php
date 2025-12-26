<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Webhooks;

use Kanvas\Connectors\DealerSocket\Actions\PullLeadAction;
use Kanvas\Connectors\DealerSocket\Actions\PullPeopleAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Actions\PullLeadFromADFAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kiwilan\XmlReader\XmlReader;
use Override;

class ProcessADFAgentInboundLeadJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $app = $this->webhookRequest->receiverWebhook->app;
        $company = $this->webhookRequest->receiverWebhook->company;
        $user = $this->webhookRequest->receiverWebhook->user;

        // Parse XML
        $xml = XmlReader::make($payload['body-plain'], true, true);
        $data = $xml->toArray();

        if (! isset($data['adf']['prospect'])) {
            return [
                'status' => 'failed',
                'message' => 'No prospect data found in ADF payload',
            ];
        }

        // Extract email safely
        $emailData = $data['adf']['prospect']['customer']['contact']['email'] ?? null;
        $email = is_array($emailData) ? ($emailData['@content'] ?? null) : $emailData;

        // Extract phone safely
        $phoneData = $data['adf']['prospect']['customer']['contact']['phone'] ?? null;
        $phone = is_array($phoneData) ? ($phoneData['@content'] ?? null) : $phoneData;

        // First attempt: pull lead directly from ADF
        $existingLead = new PullLeadFromADFAction($this->webhookRequest)->execute();

        // Initialize pull-lead action for reuse
        $pullLeadAction = new PullLeadAction($app, $company, $user);

        // If ADF didn’t provide a lead, look up the person in CRM
        if ($existingLead === null) {
            $people = new PullPeopleAction($app, $company, $user)->execute(
                email: $email,
                phoneNumber: $phone
            );

            if ($people === null) {
                return [
                    'status' => 'failed',
                    'message' => 'No lead or people found for the provided ADF data',
                ];
            }

            // Pull/create lead from CRM customer ID
            $lead = $pullLeadAction->execute(
                customerId: $people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value),
                triggerFirstMessage: true
            );
        } else {
            // Lead exists → refresh/update from CRM
            $lead = $pullLeadAction->execute(
                customerId: $existingLead->people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value),
                triggerFirstMessage: true
            );
        }

        return [
            'status' => 'success',
            'message' => 'ADF Agent Inbound Lead Processed',
            'lead_id' => is_object($lead) ? $lead->getId() : $lead,
        ];
    }
}
