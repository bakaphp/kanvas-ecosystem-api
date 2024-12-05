<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Actions\SyncZohoAgentAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;

class SyncZohoAgentFromReceiverJob extends ProcessWebhookJob
{
    public function execute(): array
    {
        $email = $this->webhookRequest->payload['email'] ?? null;

        if (! $email) {
            return [
                'message' => 'Email not found',
            ];
        }

        $syncZohoAgent = new SyncZohoAgentAction(
            $this->receiver->app,
            $this->receiver->company,
            $email
        );
        $agent = $syncZohoAgent->execute();

        return [
            'message' => 'Agent created successfully via receiver ' . $this->receiver->uuid,
            'agent' => $agent->getId(),
        ];
    }
}
