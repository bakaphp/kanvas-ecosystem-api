<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Regions;

use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Kanvas\Inventory\Regions\Repositories\RegionRepository as RegionRepository;
use Baka\Support\Str;
use Illuminate\Support\Facades\Validator;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Support\Validations\UniqueSlugRule;

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
        $user = auth()->user();
        if (! $user->isAppOwner()) {
            unset($request['companies_id']);
        }

        $regionDto = RegionDto::viaRequest($request);

        $validator = Validator::make(
            ['slug' => Str::slug($regionDto->name)],
            ['slug' => new UniqueSlugRule($regionDto->app, $regionDto->company, new RegionModel())]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        return (new CreateRegionAction($regionDto, $user))->execute();
    }

    /**
     * update.
     *
     * @return Regions
     */
    public function update(mixed $root, array $request): RegionModel
    {
        $id = (int) $request['id'];
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
        $id = (int) $request['id'];
        $region = RegionRepository::getById($id, auth()->user()->getCurrentCompany());
        $region->delete();

        return true;
    }
}
