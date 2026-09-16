<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Workflow;

use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushPeopleAction;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

#[WorkflowAction]
class PushPeopleActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(People $people, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::DRIVE_CENTRIC,
            additionalParams: $params,
            integrationOperation: function (
                People $people,
                Apps $app,
                mixed $integrationCompany,
                array $additionalParams
            ): array {
                try {
                    $result = new PushPeopleAction($people)->execute();
                } catch (DriveCentricException $e) {
                    if (! $e->isDataRejection()) {
                        throw $e;
                    }

                    Log::warning('Push people to DriveCentric failed', [
                        'people_id' => $people->getId(),
                        'company_id' => $people->company->getId(),
                        'error' => $e->getMessage(),
                    ]);

                    return $this->failWorkflow([
                        'error' => $e->getMessage(),
                        'people_id' => $people->getId(),
                    ]);
                }

                return [
                    'message' => 'People pushed successfully to DriveCentric',
                    'entity' => $result,
                ];
            },
            company: $people->company,
        );
    }
}
