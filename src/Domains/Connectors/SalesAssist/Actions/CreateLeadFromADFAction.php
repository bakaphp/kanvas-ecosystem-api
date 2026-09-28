<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Connectors\SalesAssist\Services\AdfXmlParserService;
use Kanvas\Workflow\Models\ReceiverWebhookCall;

class CreateLeadFromADFAction
{
    public function __construct(
        protected ReceiverWebhookCall $webhookRequest
    ) {
    }

    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $data = AdfXmlParserService::toArray($payload['body-plain'] ?? null);

        if (! isset($data['adf']['prospect'])) {
            return [
                'error' => 'ADF prospect not found in payload',
            ];
        }

        new PullLeadFromADFAction($this->webhookRequest)->execute();

        return [
            'body-plain' => $payload['body-plain'] ?? null,
            'stripped-text' => $payload['stripped-text'] ?? null,
        ];
    }
}
