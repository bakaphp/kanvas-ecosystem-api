<?php

namespace Kanvas\Connectors\TeeTime\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;

class CompanySetupActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $event, AppInterface $app, array $params = []): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $event,
            app: $app,
            integration: IntegrationsEnum::TEE_TIME,
            additionalParams: $params,
            integrationOperation: function ($event, $app, $integrationCompany, $additionalParams) use ($params) {
                

                return [
                    'event' => $event->getId(),
                    'status' => 'success',
                    'event_name' => $eventName,
                    'message' => 'Event synced correctly',
                    'data' => $event->toArray(),
                    'response' => $event->toArray(),
                ];
            },
            company: $event->company,
        );
    }
}
