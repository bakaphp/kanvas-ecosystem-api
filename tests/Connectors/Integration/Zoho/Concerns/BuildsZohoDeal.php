<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho\Concerns;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Pipelines\Models\Pipeline;
use Kanvas\Guild\Pipelines\Models\PipelineStage;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;
use Kanvas\Users\Models\Users;
use Tests\Traits\MakesGuildEntities;

/**
 * Fixtures live on a fresh company so the Zoho settings a test writes (field map, default owner)
 * never leak into the shared test company other suites read.
 */
trait BuildsZohoDeal
{
    use MakesGuildEntities;

    protected function makeZohoCompany(): Companies
    {
        return Companies::factory()->create();
    }

    protected function makeStage(Companies $company, string $name): PipelineStage
    {
        $pipeline = Pipeline::create([
            'slug' => 'zoho-' . uniqid(),
            'companies_id' => $company->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'users_id' => auth()->user()->getId(),
            'system_modules_id' => SystemModulesRepository::getByModelName(Deal::class, app(Apps::class))->getId(),
            'name' => 'Zoho Pipeline',
            'weight' => 0,
        ]);

        return PipelineStage::create([
            'pipelines_id' => $pipeline->getId(),
            'name' => $name,
            'weight' => 1,
        ]);
    }

    protected function makeDeal(
        Companies $company,
        ?Users $owner = null,
        ?PipelineStage $stage = null,
        ?Lead $lead = null,
        ?People $people = null
    ): Deal {
        $factory = Deal::factory()
            ->withCompanyId($company->getId())
            ->state(['description' => 'Pushed from a test']);

        if ($owner !== null) {
            $factory = $factory->withOwnerId($owner->getId());
        }

        if ($stage !== null) {
            $factory = $factory->withStage($stage);
        }

        if ($lead !== null) {
            $factory = $factory->state(['leads_id' => $lead->getId()]);
        }

        if ($people !== null) {
            $factory = $factory->state(['people_id' => $people->getId()]);
        }

        return $factory->create();
    }
}
