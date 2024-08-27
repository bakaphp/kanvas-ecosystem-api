<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as IntegrationsCompanyModel;

class CreateIntegrationCompanyAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected IntegrationsCompany $dto,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return IntegrationsCompanyModel
     */
    public function execute(): IntegrationsCompanyModel
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->dto->company,
            $this->user
        );

        return IntegrationsCompanyModel::firstOrCreate([
            'companies_id' => $this->dto->company->getId(),
            'integrations_id' => $this->dto->integration->getId(),
            'region_id' => $this->dto->region->getId(),
        ], [
            'status_id' => 1,
        ]);
    }
}
