<?php

declare(strict_types=1);

namespace App\GraphQL\Workflow\Mutations\Integrations;

use Kanvas\Inventory\Status\Models\Status as StatusModel;
use Kanvas\Workflow\Models\Integrations;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Regions\Repositories\RegionRepository;
use Kanvas\Workflow\Integrations\Actions\CreateIntegrationCompanyAction;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as ModelsIntegrationsCompany;
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

        (new ConfigValidation($integration->config, $request['input']))->validate();
        $integrationDto = new IntegrationsCompany(
            integration: $integration,
            region: $region,
            company: $company,
            config: json_decode(json_encode($request['input']['config']), true),
            app: app(Apps::class)
        );

        $integrationCompany = (new CreateIntegrationCompanyAction($integrationDto, auth()->user()))->execute();

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
