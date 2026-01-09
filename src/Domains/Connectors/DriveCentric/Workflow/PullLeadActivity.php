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
            additionalParams: $params,
            integrationOperation: function ($lead, $app, $integrationCompany, $additionalParams) use ($params): array {
                $pullAction = new PullLeadAction($app, $lead->company, $lead->user);

                $result = $pullAction->execute($lead);

                return [
                    'message' => 'Lead refreshed successfully from DriveCentric',
                    'entity' => $pullAction->getFormattedResponse($result),
                ];
            },
            company: $lead->company,
        );
    }
}
