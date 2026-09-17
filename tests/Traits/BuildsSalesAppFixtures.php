<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Support\Str;
use Kanvas\ActionEngine\Actions\Models\Action;
use Kanvas\ActionEngine\Actions\Models\CompanyAction;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;

/**
 * @todo replace with factories once the ActionEngine models have them
 */
trait BuildsSalesAppFixtures
{
    protected function makeSalesAppAction(string $name): Action
    {
        return Action::create([
            'companies_id' => 0,
            'apps_id' => 0,
            'users_id' => 0,
            'pipelines_id' => 0,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(8)),
            'name' => $name,
            'description' => $name . ' description',
            'is_active' => 1,
            'is_published' => 1,
        ]);
    }

    protected function makeSalesApp(
        Action $action,
        Companies $company,
        bool $isActive,
        float $weight = 1
    ): CompanyAction {
        return CompanyAction::create([
            'companies_id' => $company->getId(),
            'companies_branches_id' => $company->branch()->firstOrFail()->getId(),
            'apps_id' => app(Apps::class)->getId(),
            'users_id' => auth()->user()->getId(),
            'actions_id' => $action->getId(),
            'pipelines_id' => 0,
            'name' => $action->name,
            'is_active' => $isActive ? 1 : 0,
            'is_published' => 1,
            'weight' => $weight,
        ]);
    }
}
