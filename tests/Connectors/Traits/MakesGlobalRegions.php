<?php

declare(strict_types=1);

namespace Tests\Connectors\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Regions\Models\Regions;

trait MakesGlobalRegions
{
    /**
     * An app-global region (companies_id = 0) resolves for any company through
     * getByIdFromCompanyAppOrGlobal, so tests don't depend on which company the user
     * currently sits in.
     */
    protected function makeGlobalRegion(
        Apps $app,
        string $slug,
        string $shortSlug,
        ?int $currencyId = null,
        array $settings = []
    ): Regions {
        $region = new Regions();
        $region->apps_id = $app->getId();
        $region->companies_id = 0;
        $region->users_id = 0;
        $region->name = $slug;
        $region->slug = $slug;
        $region->short_slug = $shortSlug;
        $region->currency_id = $currencyId ?? Currencies::getByCode('USD')->getId();
        $region->is_default = 0;
        $region->settings = $settings;
        $region->saveOrFail();

        return $region;
    }
}
