<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushPeopleAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

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
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) use ($params): array {
                $pushAction = new PushPeopleAction($people);
                $result = $pushAction->execute();

                // Handle credit app if provided
                if (! empty($params['credit_app'])) {
                    $pushAction->addCreditApp($params['credit_app']);
                }

                // Handle additional contact info if provided
                if (! empty($params['contact_info'])) {
                    $pushAction->addContact($params['contact_info']);
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
