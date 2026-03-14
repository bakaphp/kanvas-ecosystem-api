<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Facebook\Webhooks;

use Kanvas\Connectors\Facebook\Actions\CreateLeadFromFacebookAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

class ProcessFacebookLeadWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return new CreateLeadFromFacebookAction($this->webhookRequest)->execute();
    }
}
