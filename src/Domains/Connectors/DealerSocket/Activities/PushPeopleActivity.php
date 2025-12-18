<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Activities;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\DealerSocket\Actions\PushPeopleAction;
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
            integration: IntegrationsEnum::DEALERSOCKET,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) {
                $data = new PushPeopleAction(
                    people: $people
                )->execute();

                return [
                    'message' => 'People pushed successfully',
                    //'entity' => $pushLead,
                    'data' => $data,
                ];
            },
            company: $people->company,
        );
    }
}
