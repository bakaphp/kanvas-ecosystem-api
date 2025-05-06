<?php

declare(strict_types=1);

namespace Kanvas\Connectors\IPlus\Workflows\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\IPlus\Actions\SavePeopleToIPlusAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class SyncPeopleWithIPlusActivities extends KanvasActivity
{
    public function execute(People $people, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::IPLUS,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) use ($params) {
                $createPeopleAction = new SavePeopleToIPlusAction($people);
                $response = $createPeopleAction->execute();

                return [
                    'status' => 'success',
                    'message' => 'People synced with IPlus',
                    'response' => $response,
                ];
            },
            company: $people->company,
        );
    }
}
