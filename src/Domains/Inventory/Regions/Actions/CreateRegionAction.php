<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\Validator;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;
use Kanvas\Inventory\Support\Validations\UniqueSlugRule;

class CreateRegionAction
{
    public function __construct(
        protected RegionDto $data,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return RegionModel
     */
    public function execute(): RegionModel
    {
        CompaniesRepository::userAssociatedToCompany(
            Companies::getById($this->data->company->getId()),
            $this->user
        );

        $validator = Validator::make(
            ['slug' => Str::slug($this->data->name)],
            ['slug' => new UniqueSlugRule($this->data->app, $this->data->company, new RegionModel())]
        );

        if ($validator->fails()) {
            throw new ValidationException($validator->messages()->__toString());
        }

        return RegionModel::firstOrCreate([
            'name' => $this->data->name,
            'companies_id' => $this->data->company->getId(),
            'apps_id' => $this->data->app->getId(),
        ], [
            'users_id' => $this->data->user->getId(),
            'currency_id' => $this->data->currency->getId(),
            'short_slug' => $this->data->short_slug,
            'settings' => $this->data->settings,
            'is_default' => $this->data->is_default,
        ]);
    }
}
