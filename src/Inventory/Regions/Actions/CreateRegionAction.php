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
    public function execute() : RegionModel
    {
        CompaniesRepository::userAssociatedToCompany(
            Companies::getById($this->data->companies_id),
            $this->user
        );

        return RegionModel::firstOrCreate([
            'name' => $this->data->name,
            'companies_id' => $this->data->companies_id,
            'apps_id' => $this->data->apps_id,
        ], [
            'users_id' => $this->data->users_id,
            'currency_id' => $this->data->currency_id,
            'short_slug' => $this->data->short_slug,
            'settings' => $this->data->settings,
            'is_default' => $this->data->is_default,
        ]);
    }
}
