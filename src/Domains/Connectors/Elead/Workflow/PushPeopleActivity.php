<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\SyncPeopleAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushPeopleActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(People $people, Apps $app, array $params): array
    {
        $this->overwriteAppService($app);
        if (! $people->company->get(CustomFieldEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in Elead',
            ];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) {
                $syncPeople = new SyncPeopleAction($people)->execute();

                return [
                    'message' => 'People pushed successfully',
                    'entity' => $syncPeople,
                ];
            },
            company: $people->company,
        );
    }
}
