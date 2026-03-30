<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Microsoft\Actions\SyncMicrosoftEmailAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

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
