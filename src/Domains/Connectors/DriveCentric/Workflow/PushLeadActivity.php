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

    public function execute(Lead $model, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $model,
            app: $app,
            integration: IntegrationsEnum::DRIVE_CENTRIC,
            integrationOperation: function ($model, $app, $integrationCompany, $additionalParams) use ($params): array {
                $pushLead = new PushLeadAction($app)->execute();
                return [
                    'message' => 'People pulled successfully',
                    'entity' => $pushLead,
                ];
            },
            company: $model->company,
        );
    }
}
