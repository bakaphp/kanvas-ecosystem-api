<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Regions\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Regions\DataTransferObject\Region as RegionDto;
use Kanvas\Inventory\Regions\Models\Regions as RegionModel;

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
