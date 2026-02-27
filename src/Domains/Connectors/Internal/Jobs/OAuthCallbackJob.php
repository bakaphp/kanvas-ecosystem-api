<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Internal\Jobs;

use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class OAuthCallbackJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return [
            'message' => 'OAuth callback processed via receiver webhook',
            'payload' => $this->webhookRequest->payload,
        ];
    }
}
