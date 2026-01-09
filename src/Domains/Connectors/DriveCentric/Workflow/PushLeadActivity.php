<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
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
            integration: IntegrationsEnum::DRIVE_CENTRIC,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params): array {
                $pushAction = new PushLeadAction($lead);
                $result = $pushAction->execute();

                // Handle vehicle of interest if provided
                if (! empty($params['vehicle_of_interest'])) {
                    $pushAction->addVehicleOfInterest($params['vehicle_of_interest']);
                }

                // Handle trade-in if provided
                if (! empty($params['trade_in'])) {
                    $pushAction->addTradeIn($params['trade_in']);
                }

                return [
                    'message' => 'Lead pushed successfully to DriveCentric',
                    'entity' => $result,
                ];
            },
            company: $lead->company,
        );
    }
}
