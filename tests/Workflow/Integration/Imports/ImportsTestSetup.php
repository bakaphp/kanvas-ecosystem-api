<?php

declare(strict_types=1);

namespace Tests\Workflow\Integration\Imports;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Regions\Models\Regions;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Tests\Workflow\Integration\Imports\Fakes\FakeImportHandler;

trait ImportsTestSetup
{
    private Companies $company;
    private Integrations $integration;

    private function createIntegrationRows(string $statusSlug = 'active', bool $isActive = true): IntegrationsCompany
    {
        $app = app(Apps::class);
        $this->company = $this->freshCompany();
        $this->integration = Integrations::create([
            'apps_id' => $app->getId(),
            'name' => 'fake',
            'handler' => FakeImportHandler::class,
            'type' => 'key',
        ]);

        return IntegrationsCompany::create([
            'apps_id' => $app->getId(),
            'companies_id' => $this->company->getId(),
            'integrations_id' => $this->integration->getId(),
            'region_id' => $this->region($app)->getId(),
            'status_id' => Status::where('slug', $statusSlug)->where('apps_id', 0)->firstOrFail()->getId(),
            'is_active' => $isActive,
        ]);
    }

    private function region(Apps $app): Regions
    {
        return Regions::getDefault($this->company, $app) ?? Regions::create([
            'apps_id' => $app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => 0,
            'name' => 'Region ' . uniqid(),
            'is_default' => 1,
            'is_deleted' => 0,
        ]);
    }
}
