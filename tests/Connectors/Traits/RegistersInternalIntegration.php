<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

/**
 * The lean counterpart of HasIntegrationCompany::setIntegration(): only the IntegrationsCompany row an
 * activity needs to get past executeIntegration(), no inventory Setup or user association. INTERNAL by
 * default; a connector activity passes its own integration.
 */
trait RegistersInternalIntegration
{
    protected function registerInternalIntegration(IntegrationsEnum $integration = IntegrationsEnum::INTERNAL): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $region = Regions::getDefault($company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        IntegrationsCompany::firstOrCreate(
            [
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'integrations_id' => Integrations::getByName($integration->value)->getId(),
                'region_id' => $region->getId(),
            ],
            [
                'status_id' => Status::where('slug', StatusEnum::ACTIVE->value)->where('apps_id', 0)->firstOrFail()->getId(),
                'is_active' => 1,
            ]
        );
    }
}
