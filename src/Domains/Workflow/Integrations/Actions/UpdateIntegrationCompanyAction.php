<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Status\Models\Status;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as IntegrationsCompanyModel;

class UpdateIntegrationCompanyAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected IntegrationsCompanyModel $integrationCompany,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return IntegrationsCompanyModel
     */
    public function execute(): void
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->integrationCompany->company,
            $this->user
        );

        // return IntegrationsCompanyModel::firstOrCreate([
        //     'companies_id' => $this->dto->company->getId(),
        //     'integrations_id' => $this->dto->integration->getId(),
        //     'region_id' => $this->dto->region->getId(),
        // ], [
        //     'status_id' => 1,
        // ]);
    }
}
