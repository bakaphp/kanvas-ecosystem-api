<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Actions\PushLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushLeadActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(Lead $lead, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $lead,
            app: $app,
            integration: IntegrationsEnum::DEALERSOCKET,
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) {
                $data = new PushLeadAction(
                    lead: $lead
                )->execute();

                return [
                    'message' => 'Lead pushed successfully',
                    //'entity' => $pushLead,
                    'data' => $data,
                ];
            },
            company: $lead->company,
        );
    }
}
