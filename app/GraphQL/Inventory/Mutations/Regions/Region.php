<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Regions;

use Baka\Support\Str;
use Baka\Traits\ResolvesTargetCompanyTrait;
use Illuminate\Support\Facades\Validator;
use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\Actions\CreateRegionAction;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Enums\CustomFieldEnum;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Kanvas\Inventory\Regions\Repositories\RegionRepository as RegionRepository;
use Kanvas\Support\Validations\UniqueSlugRule;

class Region
{
    use ResolvesTargetCompanyTrait;
    /**
     * create.
     *
     * @return Regions
     */
    public function create(mixed $root, array $request): RegionModel
    {
        $request = $request['input'];
        $user = auth()->user();
        $targetCompaniesId = $this->resolveTargetCompaniesId($user, $request);

        unset($request['companies_id']);

        $regionDto = RegionDto::viaRequest($request);

        $validator = Validator::make(
            ['slug' => Str::slug($regionDto->name)],
            ['slug' => new UniqueSlugRule($regionDto->app, $regionDto->company, new RegionModel())]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        $region = (new CreateRegionAction($regionDto, $user))->execute();
        $this->applyTargetCompaniesId($region, $targetCompaniesId);

        return $region;
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
        $region = RegionRepository::getByIdOrGlobal($id, auth()->user()->getCurrentCompany());
        $region->update($request);

        return $region;
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $request): bool
    {
        $id = (int) $request['id'];
        $region = RegionRepository::getByIdOrGlobal($id, auth()->user()->getCurrentCompany());
        $region->delete();

        return true;
    }

    public function setDefaultRegion(mixed $root, array $request): bool
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $region = RegionModel::getByIdFromCompanyAppOrGlobal(
            (int) $request['region_id'],
            $user->getCurrentCompany(),
            $app
        );
        $user->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $region->getId(), isPublic: 1);

        return true;
    }

    /**
     * TODO: fold this into createRegion/updateRegion — the UI shouldn't need a third
     * mutation to mark a region as the company's default. Blocker: RegionObserver's
     * default bookkeeping only looks at the regions table, while this writes a custom
     * field (the only form that can point at a global region), so both notions of
     * "default" have to be reconciled first.
     */
    public function setCompanyDefaultRegion(mixed $root, array $request): bool
    {
        $company = auth()->user()->getCurrentCompany();
        $region = RegionModel::getByIdFromCompanyAppOrGlobal(
            (int) $request['region_id'],
            $company,
            app(Apps::class)
        );
        $company->set(CustomFieldEnum::DEFAULT_REGION_ID->value, $region->getId(), isPublic: 1);

        return true;
    }
}
