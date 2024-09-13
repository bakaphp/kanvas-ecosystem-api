<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Integrations;

use Kanvas\Inventory\Status\Models\Status as StatusModel;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\InternalServerErrorException;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as ModelsIntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Integrations\Validations\ConfigValidation;

class IntegrationsMutation
{
    /**
     * create.
     *
     * @param  mixed $rootValue
     * @param  array $args
     *
     * @return StatusModel
     */
    public function createIntegrationCompany(mixed $rootValue, array $request): ModelsIntegrationsCompany
    {
        $integration = Integrations::getById((int) $request['input']['integration']['id']);
        $company = CompaniesRepository::getById((int) $request['input']['company_id']);
        $region = RegionRepository::getById((int) $request['input']['region']['id'], $company);
        $user = auth()->user();
    
        if(! $user->isAppOwner()) {
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
            $integrationDto->app,
            $integrationDto->company,
            $integrationDto->region,
            $integrationDto->config
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
}
