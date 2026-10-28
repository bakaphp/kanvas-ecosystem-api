<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Jobs;

use Kanvas\Connectors\Zoho\Actions\PullZohoDealAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'Zoho Deal Webhook',
    description: 'Receiver for Zoho deals: creates or updates the matching Kanvas deal, links it to its lead '
        . 'and person, and pulls down any attachments. Inbound one-way. The deal workflow it fires is '
        . 'tagged as Zoho-originated, so Zoho Push Deal skips it and a change cannot bounce back out.',
    integration: IntegrationsEnum::ZOHO,
)]
class ProcessZohoDealWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = is_array($this->webhookRequest->payload) ? $this->webhookRequest->payload : [];
        $zohoDealId = $payload['DealID'] ?? $payload['id'] ?? null;

        if ($zohoDealId === null || $zohoDealId === '') {
            return ['message' => 'Zoho Deal ID not found in payload'];
        }

        $zohoDealId = (string) $zohoDealId;

        $deal = new PullZohoDealAction(
            $this->receiver->app,
            $this->receiver->company,
            $this->receiver->user,
            $zohoDealId
        )->execute();

        if ($deal === null) {
            return [
                'message' => 'Zoho Deal not found',
                'zoho_deal_id' => $zohoDealId,
            ];
        }

        return [
            'message' => 'Zoho Deal synced to Kanvas',
            'deal_id' => $deal->getId(),
            'deal_uuid' => $deal->uuid,
            'zoho_deal_id' => $zohoDealId,
            'created' => $deal->wasRecentlyCreated,
        ];
    }
}
