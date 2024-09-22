<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as IntegrationsCompanyModel;
use Kanvas\Workflow\Integrations\Models\Status;

class CreateIntegrationCompanyAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected IntegrationsCompany $dto,
        protected UserInterface $user,
        protected Status $status
    ) {
    }

    /**
     * execute.
     *
     * @return IntegrationsCompanyModel
     */
    public function execute(): IntegrationsCompanyModel
    {
        return IntegrationsCompanyModel::firstOrCreate([
            'companies_id' => $this->dto->company->getId(),
            'integrations_id' => $this->dto->integration->getId(),
            'region_id' => $this->dto->region->getId(),
        ], [
            'status_id' => $this->status->getId(),
        ]);
    }
}
