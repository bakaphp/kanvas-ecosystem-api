<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Webhooks;

use Kanvas\Connectors\VinSolution\Actions\PullLeadAction;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class VinSolutionLeadCreationWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        // Extract webhook data
        $payload = $this->webhookRequest->payload;

        // Get lead ID from the payload
        $leadId = $this->extractLeadId($payload);

        if ($leadId === null) {
            throw new ValidationException('Lead ID not found in webhook payload');
        }

        // Use PullLeadAction to fetch and create/update the lead
        $pullLeadAction = new PullLeadAction(
            $this->receiver->app,
            $this->receiver->company,
            $this->receiver->user
        );

        $result = $pullLeadAction->execute(null, $leadId);

        return [
            'success' => true,
            'lead_id' => $leadId,
            'processed_leads' => $result,
            'message' => 'Lead processed successfully',
        ];
    }

    /**
     * Extract lead ID from various possible payload structures.
     */
    private function extractLeadId(array $payload): ?int
    {
        // Try different possible structures for the lead ID
        $leadId = null;

        // Direct lead ID in payload
        if (isset($payload['lead_id'])) {
            $leadId = (int) $payload['lead_id'];
        }
        // Lead ID in data section
        elseif (isset($payload['data']['lead_id'])) {
            $leadId = (int) $payload['data']['lead_id'];
        }
        // Lead ID in event data
        elseif (isset($payload['data']['LeadId'])) {
            $leadId = (int) $payload['data']['LeadId'];
        }
        // Lead ID in nested structure
        elseif (isset($payload['event_data']['lead_id'])) {
            $leadId = (int) $payload['event_data']['lead_id'];
        }
        // Lead ID in resource section (common in webhook patterns)
        elseif (isset($payload['resource']['id'])) {
            $leadId = (int) $payload['resource']['id'];
        }
        // Try to extract from any 'id' field if it looks like a lead
        elseif (isset($payload['id']) && is_numeric($payload['id'])) {
            $leadId = (int) $payload['id'];
        }

        // Validate that we have a positive integer
        return $leadId !== null && $leadId > 0 ? $leadId : null;
    }
}
