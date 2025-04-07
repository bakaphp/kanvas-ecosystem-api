<?php

declare(strict_types=1);

namespace Kanvas\Workflow\Integrations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\Integrations;

class EntityIntegrationHistoryService
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
    }

    /**
     * Return the integrations history based on integration and/or region
     *
     * @param Integrations $integration
     * @param Regions|null $region
     * @return array
     */
    public function getByIntegration(Integrations $integration, ?Regions $region = null): array
    {
        $integrationStatus = [];

        $integrationsCompany = IntegrationsCompany::where('integrations_id', $integration->getId())
                                ->where('companies_id', $this->company->getId())
                                ->when($region, function ($query, $region) {
                                    return $query->where('region_id', $region->getId());
                                })
                                ->get();

        foreach ($integrationsCompany as $integrationCompany) {
            if ($integrationCompany->history()->where('apps_id', $this->app->getId())->exists()) {
                $integrationStatus = array_merge(
                    $integrationStatus,
                    $integrationCompany->history->map(function ($history) {
                        return $history;
                    })->all()
                );
            };
        }
        return $integrationStatus;
    }
}
