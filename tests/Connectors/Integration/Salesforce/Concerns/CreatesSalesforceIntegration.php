<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Salesforce\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Salesforce\Handlers\SalesforceHandler;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;

trait CreatesSalesforceIntegration
{
    private function salesforceIntegrationFor(Companies $company, bool $isActive = true): IntegrationsCompany
    {
        $app = app(Apps::class);
        $integration = Integrations::where('name', 'salesforce')->first() ?? Integrations::create([
            'apps_id' => 0,
            'name' => 'salesforce',
            'handler' => SalesforceHandler::class,
            'type' => 'key',
        ]);
        $region = Regions::getDefault($company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);

        return IntegrationsCompany::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'integrations_id' => $integration->getId(),
            'region_id' => $region->getId(),
            'status_id' => Status::where('slug', 'active')->where('apps_id', 0)->firstOrFail()->getId(),
            'is_active' => $isActive,
        ]);
    }
}
