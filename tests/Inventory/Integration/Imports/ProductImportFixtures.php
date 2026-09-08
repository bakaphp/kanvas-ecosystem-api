<?php

declare(strict_types=1);

namespace Tests\Inventory\Integration\Imports;

use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region;
use Kanvas\Inventory\Regions\Models\Regions;

trait ProductImportFixtures
{
    private function makeRegion(CompanyInterface $company, Apps $app, UserInterface $user): Regions
    {
        return new CreateRegionAction(
            new Region(
                $company,
                $app,
                $user,
                Currencies::getById(1),
                'Region ' . uniqid(),
                'r-' . uniqid(),
                null,
                1,
            ),
            $user,
        )->execute();
    }

    private function findImportedProduct(string $slug, Apps $app, CompanyInterface $company): ?Products
    {
        return Products::where('slug', $slug)
            ->where('apps_id', $app->getId())
            ->where('companies_id', $company->getId())
            ->first();
    }
}
