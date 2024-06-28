<?php

declare(strict_types=1);

namespace Kanvas\Guild\Leads\Jobs;

use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\Actions\CreateLeadAttemptAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead;
use Kanvas\Guild\Leads\Models\LeadReceiver;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class CreateLeadsFromReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $leadReceiver = LeadReceiver::getByIdFromCompanyApp($this->receiver->configuration['receiver_id'], $this->receiver->company, $this->receiver->app);
        $ipAddresses = $this->webhookRequest->headers['x-real-ip'] ?? [];
        $realIp = is_array($ipAddresses) && ! empty($ipAddresses) ? reset($ipAddresses) : '127.0.0.1';

        $leadAttempt = new CreateLeadAttemptAction(
            $this->webhookRequest->payload,
            $this->webhookRequest->headers,
            $this->receiver->company,
            $this->receiver->app,
            $realIp,
            'RECEIVER ID: ' . $leadReceiver->getId()
        );
        $attempt = $leadAttempt->execute();

        $payload = $this->webhookRequest->payload;
        $payload['branch_id'] = $leadReceiver->companies_branches_id;

        $createLead = new CreateLeadAction(
            Lead::viaRequest(
                $leadReceiver->user,
                $this->receiver->app,
                $payload
            ),
            $attempt
        );

        $lead = $createLead->execute();

        return [
            'message' => 'Lead created successfully via receiver ' . $leadReceiver->uuid,
            'receiver' => $leadReceiver->getId(),
            'lead' => $lead->getId(),
        ];
    }
}
