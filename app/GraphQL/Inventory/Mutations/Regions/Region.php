<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Regions;

use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Kanvas\Currencies\Models\Currencies;
use Kanvas\Apps\Models\Apps;
use Kanvas\Inventory\Regions\Repositories\Region as RegionRepository;

class Region
{
    /**
     * create
     *
     * @param  mixed $root
     * @param  array $request
     * @return Regions
     */
    public function create(mixed $root, array $request): RegionModel
    {
        $request = $request['input'];
        $request['companies_id'] = auth()->user()->default_company;
        $request['apps_id'] = app(Apps::class)->id;
        $currency = Currencies::findOrFail($request['currency_id']);
        $regionDto = RegionDto::fromArray($request);
        $region = (new CreateRegionAction($regionDto))->execute();
        return $region;
    }

    /**
     * update
     *
     * @param  mixed $root
     * @param  array $request
     * @return Regions
     */
    public function update(mixed $root, array $request): RegionModel
    {
        $id = $request['id'];
        $request = $request['input'];
        $region = RegionRepository::getById($id);
        $region->update($request);
        return $region;
    }

    /**
     * delete
     *
     * @param  mixed $root
     * @param  array $request
     * @return bool
     */
    public function delete(mixed $root, array $request): bool
    {
        $id = $request['id'];
        $region = RegionRepository::getById($id);
        $region->delete();
        return true;
    }
}
