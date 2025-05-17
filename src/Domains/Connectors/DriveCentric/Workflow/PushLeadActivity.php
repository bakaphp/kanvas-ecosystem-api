<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Workflow;

use Illuminate\Database\Eloquent\Model as EloquentModel;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Kanvas\Connectors\DriveCentric\Actions\PushLeadAction;
use Kanvas\Guild\Leads\Models\Lead;
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
                $pushLead = new PushLeadAction($lead)->execute();
                return [
                    'message' => 'People pulled successfully',
                    'entity' => $pushLead,
                ];
            },
            company: $lead->company,
        );
    }
}