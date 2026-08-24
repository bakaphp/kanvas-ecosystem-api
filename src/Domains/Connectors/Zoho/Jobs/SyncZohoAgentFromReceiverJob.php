<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Actions\SyncZohoAgentAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;
use Throwable;

#[WorkflowAction(
    name: 'Zoho Sync Agent Webhook',
    description: 'Receiver that pulls a Zoho agent (salesperson) record into Kanvas. Inbound one-way.',
    integration: IntegrationsEnum::ZOHO,
)]
class SyncZohoAgentFromReceiverJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $email = $this->webhookRequest->payload['email'] ?? null;

        if (! $email) {
            return [
                'message' => 'Email not found',
            ];
        }

        try {
            $syncZohoAgent = new SyncZohoAgentAction(
                $this->receiver->app,
                $this->receiver->company,
                $email
            );
            $agent = $syncZohoAgent->execute();
        } catch (Throwable $e) {
            return [
                'message' => 'Error syncing Zoho agent: ' . $e->getMessage(),
            ];
        }

        return [
            'message' => 'Agent created successfully via receiver ' . $this->receiver->uuid,
            'agent' => $agent->getId(),
        ];
    }
}
