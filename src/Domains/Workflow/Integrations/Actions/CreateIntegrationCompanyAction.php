<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as IntegrationsCompanyModel;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Rules\Actions\EnsureIntegrationDefaultRulesAction;

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

    public function execute(): IntegrationsCompanyModel
    {
        $integrationCompany = IntegrationsCompanyModel::firstOrCreate([
            'companies_id' => $this->dto->company->getId(),
            'integrations_id' => $this->dto->integration->getId(),
            'region_id' => $this->dto->region->getId(),
        ], [
            'status_id' => $this->status->getId(),
        ]);

        if ($this->status->slug === StatusEnum::ACTIVE->value && $this->dto->app instanceof Apps) {
            new EnsureIntegrationDefaultRulesAction($integrationCompany, $this->dto->app, $this->user)->execute();
        }

        return $integrationCompany;
    }
}
