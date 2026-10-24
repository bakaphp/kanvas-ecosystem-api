<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Communication\Connections\Actions\ScheduleCommunicationSetupAction;
use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as IntegrationsCompanyModel;
use Kanvas\Workflow\Integrations\Models\Status;

class UpdateIntegrationCompanyAction
{
    public function __construct(
        protected IntegrationsCompanyModel $integrationCompany,
        protected IntegrationsCompany $dto,
        protected Status $status
    ) {
    }

    /**
     * Status and config move together: a reader must never see the new account selection against the
     * old setup result, or the reverse.
     */
    public function execute(): IntegrationsCompanyModel
    {
        if (! $this->integrationCompany->isManageableFrom($this->dto->app)) {
            throw new ModelNotFoundException('Integration not found for this app.');
        }

        return DB::connection($this->integrationCompany->getConnectionName())->transaction(function () {
            $this->integrationCompany->status_id = $this->status->getId();
            $this->integrationCompany->config = $this->dto->persistedConfig ?: null;
            $this->integrationCompany->saveOrFail();

            new ScheduleCommunicationSetupAction($this->dto->app, $this->integrationCompany)->execute();

            return $this->integrationCompany;
        });
    }
}
