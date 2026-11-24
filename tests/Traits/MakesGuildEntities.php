<?php

declare(strict_types=1);

namespace Tests\Traits;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

trait MakesGuildEntities
{
    protected function makeLead(?Companies $company = null): Lead
    {
        return Lead::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(($company ?? auth()->user()->getCurrentCompany())->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
    }

    protected function makePeople(?Companies $company = null): People
    {
        return People::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId(($company ?? auth()->user()->getCurrentCompany())->getId())
            ->withUserId(auth()->user()->getId())
            ->create();
    }

    /**
     * @return list<string>
     */
    protected function attachedFileNames(Model $entity): array
    {
        $names = $entity->files()->pluck('filesystem.name')->all();
        sort($names);

        return $names;
    }
}
