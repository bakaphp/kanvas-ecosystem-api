<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Services\DealerSocketLeadService;
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
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) {
                $pushLead = new DealerSocketLeadService($app, $integrationCompany->company, $integrationCompany->region);
                $data = $pushLead->saveLead($lead);

                return [
                    'message' => 'Lead pushed successfully',
                    'entity' => $pushLead,
                ];
            },
            company: $lead->company,
        );
    }
}
