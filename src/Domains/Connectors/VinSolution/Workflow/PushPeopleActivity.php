<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Workflow;

use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Actions\PushPeopleAction;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;

class PushPeopleActivity extends KanvasActivity
{
    public $tries = 3;

    public function execute(People $people, Apps $app, array $params): array
    {
        $company = $people->company;

        if (! $company->get(ConfigurationEnum::COMPANY->value)) {
            return [
                'error' => 'Company not found in VinSolution',
            ];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::VIN_SOLUTION,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) {
                $pushPeopleAction = new PushPeopleAction(
                    people: $people,
                );

                $results = $pushPeopleAction->execute();

                return [
                    'message' => 'VinSolution integration completed successfully',
                    'people' => $results,
                ];
            },
            company: $company,
        );
    }
}
