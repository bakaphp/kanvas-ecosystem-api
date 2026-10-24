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

/**
 * Fixtures live on a fresh company so the Zoho settings a test writes (field map, default owner)
 * never leak into the shared test company other suites read.
 */
trait BuildsZohoDeal
{
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

    protected function makeLead(Companies $company): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }

    protected function makeDeal(
        Companies $company,
        ?Users $owner = null,
        ?PipelineStage $stage = null,
        ?Lead $lead = null
    ): Deal {
        $deal = new Deal();
        $deal->apps_id = app(Apps::class)->getId();
        $deal->companies_id = $company->getId();
        $deal->users_id = auth()->user()->getId();
        $deal->owner_id = $owner?->getId() ?? auth()->user()->getId();
        $deal->title = 'Zoho Deal ' . uniqid();
        $deal->description = 'Pushed from a test';
        $deal->pipeline_id = $stage?->pipelines_id ?? 0;
        $deal->pipeline_stage_id = $stage?->getId() ?? 0;
        $deal->leads_id = $lead?->getId();
        $deal->is_deleted = 0;
        $deal->saveOrFail();

        return $deal;
    }
}
