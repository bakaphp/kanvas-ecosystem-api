<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Workflow;

use Exception;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Elead\Actions\SyncPeopleAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Support\EleadDebounce;
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

        $debounce = new EleadDebounce($app, $people->company);
        if ($debounce->shouldSkip('push_people', (string) $people->getId())) {
            return [
                'message' => 'Debounced: People sync already in progress or recently completed',
                'people_id' => $people->getId(),
            ];
        }

        return $this->executeIntegration(
            entity: $people,
            app: $app,
            integration: IntegrationsEnum::ELEAD,
            additionalParams: $params,
            integrationOperation: function ($people, $app, $integrationCompany, $additionalParams) {
                try {
                    $syncPeople = new SyncPeopleAction($people)->execute();
                } catch (Exception $e) {
                    return $this->failWorkflow([
                        'error' => 'Error pushing people to Elead: ' . $e->getMessage(),
                    ]);
                }

                return [
                    'message' => 'People pushed successfully',
                    'entity' => $syncPeople,
                ];
            },
            company: $people->company,
        );
    }
}
