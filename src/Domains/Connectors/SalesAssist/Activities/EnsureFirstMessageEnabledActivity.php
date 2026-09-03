<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Actions\EnsureFirstMessageEnabledAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Ensure Sales Assist First Message Is Enabled',
    description: 'Stops the workflow when the lead type explicitly disables its first follow-up message.',
)]
final class EnsureFirstMessageEnabledActivity extends KanvasActivity
{
    public $tries = 1;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: fn (): array => new EnsureFirstMessageEnabledAction($lead)->execute(),
            company: $lead->company,
            throwException: true,
        );
    }
}
