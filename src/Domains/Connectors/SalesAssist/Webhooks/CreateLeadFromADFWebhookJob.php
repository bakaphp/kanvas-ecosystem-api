<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Webhooks;

use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class CreateLeadFromADFWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;

        return [
            'body-plain' => $payload['body-plain'] ?? null,
            'stripped-text' => $payload['stripped-text'] ?? null,
        ];
    }
}
