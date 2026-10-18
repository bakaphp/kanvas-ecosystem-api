<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Actions\ScheduleCommunicationSetupAction;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\DataTransferObject\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany as IntegrationsCompanyModel;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Rules\Actions\EnsureIntegrationDefaultRulesAction;

class CreateIntegrationCompanyAction
{
    public function __construct(
        protected IntegrationsCompany $dto,
        protected UserInterface $user,
        protected Status $status
    ) {
    }

    /**
     * The row is keyed on the app as well, so the same company + integration + region in two apps is
     * two rows. A row with no recorded app is never matched here: nothing proves it belongs to this
     * app, and taking it over would hand one app another app's integration.
     */
    public function execute(): IntegrationsCompanyModel
    {
        return DB::connection('workflow')->transaction(function (): IntegrationsCompanyModel {
            $integrationCompany = IntegrationsCompanyModel::firstOrCreate([
                'apps_id' => $this->dto->app->getId(),
                'companies_id' => $this->dto->company->getId(),
                'integrations_id' => $this->dto->integration->getId(),
                'region_id' => $this->dto->region->getId(),
            ], [
                'status_id' => $this->status->getId(),
                'config' => $this->dto->persistedConfig ?: null,
            ]);

            // An existing row is brought up to date by UpdateIntegrationCompanyAction, which
            // schedules setup itself.
            if ($integrationCompany->wasRecentlyCreated) {
                new ScheduleCommunicationSetupAction($this->dto->app, $integrationCompany)->execute();
            }

            if ($this->status->slug === StatusEnum::ACTIVE->value && $this->dto->app instanceof Apps) {
                new EnsureIntegrationDefaultRulesAction($integrationCompany, $this->dto->app, $this->user)->execute();
            }

            return $integrationCompany;
        });
    }
}
