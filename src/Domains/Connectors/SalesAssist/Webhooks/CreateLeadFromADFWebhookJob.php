<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Webhooks;

use Kanvas\Connectors\SalesAssist\Actions\CreateLeadFromADFAction;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * @todo: change the name of this class to something more generic like LinkADFToLead
 */
class CreateLeadFromADFWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return new CreateLeadFromADFAction($this->webhookRequest)->execute();
    }
}
