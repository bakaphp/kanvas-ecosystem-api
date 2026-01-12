<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Connectors\SalesAssist\Actions\SyncLeadWithLegacyCRMAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SyncLeadWithLegacyCRMActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams): array {
                $action = new SyncLeadWithLegacyCRMAction($lead);

                return $action->execute();
            },
            company: $lead->company,
        );
    }
}
