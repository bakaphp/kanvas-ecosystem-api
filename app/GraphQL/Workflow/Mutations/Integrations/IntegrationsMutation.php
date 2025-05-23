<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Integrations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\EntityIntegrationHistory;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as ModelsIntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Integrations\Validations\ConfigValidation;
use Kanvas\Workflow\Models\Integrations;

class IntegrationsMutation
{
    /**
     * create.
     */
    public function createIntegrationCompany(mixed $rootValue, array $request): ModelsIntegrationsCompany
    {
        $integration = Integrations::getById((int) $request['input']['integration']['id']);
        $company = CompaniesRepository::getById((int) $request['input']['company_id']);
        $region = RegionRepository::getById((int) $request['input']['region']['id'], $company);
        $user = auth()->user();

        if (! $user->isAppOwner()) {
            CompaniesRepository::userAssociatedToCompany(
                $company,
                $user
            );
        }

        (new ConfigValidation($integration->config, $request['input']))->validate();
        $integrationDto = new IntegrationsCompany(
            integration: $integration,
            region: $region,
            company: $company,
            config: json_decode(json_encode($request['input']['config']), true),
            app: app(Apps::class)
        );

        if (! class_exists($handler = $integration->handler)) {
            throw new InternalServerErrorException('Handler Class not found.');
        }
        $handler = $integration->handler;

        $handlerInstance = new $handler(
            app: $integrationDto->app,
            company: $integrationDto->company,
            region: $integrationDto->region,
            data: $integrationDto->config
        );

        if ($handlerInstance->setup()) {
            $status = Status::where('slug', StatusEnum::ACTIVE->value)
                            ->where('apps_id', 0)
                            ->first();
        } else {
            $status = Status::where('slug', StatusEnum::FAILED->value)
                            ->where('apps_id', 0)
                            ->first();
        }

        $integrationCompany = (new CreateIntegrationCompanyAction($integrationDto, $user, $status))->execute();

        return $integrationCompany;
    }

    // The edit must only validate the config and re-setup the integration.
    // public function updateIntegrationCompany(mixed $rootValue, array $request): ModelsIntegrationsCompany
    // {
    //     $integration = ModelsIntegrationsCompany::getById((int) $request['integration_company_id']);

    //     (new ConfigValidation($integration->config, $request))->validate();

    // }

    public function removeIntegrationCompany(mixed $root, array $request): bool
    {
        $integrationCompany = ModelsIntegrationsCompany::getById((int) $request['id']);

        CompaniesRepository::userAssociatedToCompany(
            $integrationCompany->company,
            auth()->user()
        );

        return $integrationCompany->delete();
    }

    public function integrationCompanyIsActive(mixed $root, array $request): bool
    {
        $integrationCompany = ModelsIntegrationsCompany::getById((int) $request['input']['id']);

        CompaniesRepository::userAssociatedToCompany(
            $integrationCompany->company,
            auth()->user()
        );

        return $integrationCompany->isActive((bool) $request['input']['is_active']);
    }

    public function integrationWorkflowRetry(mixed $root, array $request): bool
    {
        $integrationWorkflow = EntityIntegrationHistory::getById((int) $request['id'], app(Apps::class));

        CompaniesRepository::userAssociatedToCompany(
            $integrationWorkflow->integrationCompany->company,
            auth()->user()
        );

        $subject = $integrationWorkflow->entity()->first();

        $subject->fireWorkflow(
            $integrationWorkflow->rules->type->name,
            true
        );

        return true;
    }
}
