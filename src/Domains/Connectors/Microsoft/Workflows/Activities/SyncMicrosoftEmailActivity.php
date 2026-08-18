<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Microsoft\Actions\SyncMicrosoftEmailAction;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction(
    name: 'Microsoft Sync Mailbox',
    description: 'Pulls recent mail from the company\'s connected Microsoft mailbox into Kanvas. Inbound '
        . 'one-way read; it sends nothing and replies to nobody. Runs against the COMPANY, not a lead '
        . 'or a message.',
    integration: IntegrationsEnum::MICROSOFT,
)]
class SyncMicrosoftEmailActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Companies $company, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $company,
            app: $app,
            integration: IntegrationsEnum::MICROSOFT,
            integrationOperation: function ($company, $app) {
                $user = $company->user;

                $syncedMessages = new SyncMicrosoftEmailAction(
                    $app,
                    $company,
                    $user,
                )->execute();

                return [
                    'message' => 'Synced ' . count($syncedMessages) . ' emails',
                    'count' => count($syncedMessages),
                ];
            },
            company: $company,
        );
    }
}
