<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Regions;

use Kanvas\Currencies\Models\Currencies;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Kanvas\Inventory\Regions\Repositories\RegionRepository as RegionRepository;

class Region
{
    /**
     * create.
     *
     * @return Regions
     */
    public function create(mixed $root, array $request): RegionModel
    {
        $request = $request['input'];
        $currency = Currencies::findOrFail($request['currency_id']);
        $user = auth()->user();
        if (! $user->isAppOwner()) {
            unset($request['companies_id']);
        }

        $regionDto = RegionDto::viaRequest($request);

        return (new CreateRegionAction($regionDto, $user))->execute();
    }

    /**
     * update.
     *
     * @return Regions
     */
    public function update(mixed $root, array $request): RegionModel
    {
        $id = $request['id'];
        $request = $request['input'];
        $region = RegionRepository::getById($id, auth()->user()->getCurrentCompany());
        $region->update($request);

        return $region;
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $request): bool
    {
        $id = $request['id'];
        $region = RegionRepository::getById($id, auth()->user()->getCurrentCompany());
        $region->delete();

        return true;
    }
}
