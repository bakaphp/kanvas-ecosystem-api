<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PullLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PullLeadActivity extends KanvasActivity
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
                $pullAction = new PullLeadAction($app, $lead->company, $lead->user);

                // Pull by deal ID if provided
                if (! empty($params['deal_id'])) {
                    $result = $pullAction->execute($params['deal_id']);

                    return [
                        'message' => 'Lead pulled successfully from DriveCentric',
                        'entity' => $pullAction->getFormattedResponse($result),
                    ];
                }

                // Pull by existing lead (refresh)
                $result = $pullAction->executeByLead($lead);

                return [
                    'message' => 'Lead refreshed successfully from DriveCentric',
                    'entity' => $pullAction->getFormattedResponse($result),
                ];
            },
            company: $lead->company,
        );
    }
}
