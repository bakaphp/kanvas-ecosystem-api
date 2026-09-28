<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Facebook\Webhooks;

use Kanvas\Connectors\Facebook\Actions\CreateLeadFromFacebookAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

#[WorkflowAction(
    name: 'Facebook Lead Ads Webhook',
    description: 'Receiver for Facebook Lead Ads: turns a submitted lead form into a Kanvas lead. Inbound only '
        . '— it is how leads ARRIVE from Facebook, so it is the start of a funnel, not a step you '
        . 'attach to an existing record.',
)]
class ProcessFacebookLeadWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return new CreateLeadFromFacebookAction($this->webhookRequest)->execute();
    }
}
