<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Webhooks;

use Kanvas\Connectors\Mailgun\Actions\CreateMessageFromEmailAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class AgentProcessEmailWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return new CreateMessageFromEmailAction($this->webhookRequest)
            ->execute()
            ->toArray();
    }
}
