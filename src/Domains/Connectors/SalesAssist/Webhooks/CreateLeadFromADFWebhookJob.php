<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Webhooks;

use Kanvas\Connectors\SalesAssist\Actions\CreateLeadFromADFAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Override;

/**
 * @todo: change the name of this class to something more generic like LinkADFToLead
 */
#[WorkflowAction(
    name: 'ADF Lead Receiver',
    description: 'Receiver that turns an inbound ADF XML lead into a Kanvas lead. Inbound only — this is how '
        . 'leads ARRIVE from a dealer system that speaks ADF.',
    integration: IntegrationsEnum::SALESASSIST,
)]
class CreateLeadFromADFWebhookJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        return new CreateLeadFromADFAction($this->webhookRequest)->execute();
    }
}
