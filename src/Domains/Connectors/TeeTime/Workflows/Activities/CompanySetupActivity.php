<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TeeTime\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
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
            integrationOperation: function ($company, $app, $integrationCompany, $additionalParams) use ($params) {
                $eventName = $additionalParams['currentEventTypeName'] ?? null;

                ProductsTypes::firstOrCreate([
                    'apps_id' => $app->getId(),
                    'companies_id' => $company->getId(),
                    'name' => 'golf course'
                ], [
                    'users_id' => $company->users_id,
                    'description' => 'TeeTime Golf Course Product Type',
                    'is_published' => 1,
                    'weight' => 0,
                ]);

                return [
                    'company' => $company->getId(),
                    'status' => 'success',
                    'event_name' => $eventName,
                    'message' => 'TeeTime Company Setup completed successfully',
                    'data' => $company->toArray(),
                    'response' => $company->toArray(),
                ];
            },
            company: $event->company,
        );
    }
}
