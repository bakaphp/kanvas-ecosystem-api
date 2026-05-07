<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Activities;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DriveCentric\Actions\PushPeopleAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushPeopleActivity extends KanvasActivity
{
    public function execute(People $people, AppInterface $app, array $params): array
    {
        $this->overwriteAppService($app);

        $useGlobalWorkflows = $people->company->get('use_global_workflows') ?? false;

        if (! $useGlobalWorkflows) {
            return ['Company is not configure to use global workflows'];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::INTERNAL,
            additionalParams: $params,
            integrationOperation: function (People $people, Apps $app, mixed $integrationCompany, array $additionalParams) {
                $company = $people->company;

                $isElead = $company->get(CustomFieldEnum::COMPANY->value) !== null;
                $isVinSolutions = $company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;
                $isDriveCentric = $company->get(ConfigurationEnum::STORE_ID->value) !== null;
                $connectedCRM = null;

                $result = [];
                if ($isDriveCentric) {
                    $connectedCRM = 'DriveCentric';
                    $result = new PushPeopleAction($people)->execute();
                }

                return [
                    'message' => 'People pushed successfully',
                    'crm' => $connectedCRM,
                    'entity' => $result,
                ];
            },
            company: $people->company,
        );
    }
}
