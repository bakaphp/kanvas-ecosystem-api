<?php
declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\Warehouses\DataTransferObject\Warehouses as WarehousesDto;
use Kanvas\Inventory\Warehouses\Models\Warehouses;

class CreateWarehouseAction
{
    /**
     * __construct.
     *
     * @param  WarehousesDto $dto
     *
     * @return void
     */
    public function __construct(
        protected WarehousesDto $data,
        protected UserInterface $user,
    ) {
    }

    /**
     * execute.
     *
     * @return Warehouses
     */
    public function execute() : Warehouses
    {
        CompaniesRepository::userAssociatedToCompany(
            Companies::getById($this->data->companies_id),
            $this->user
        );

        return Warehouses::firstOrCreate([
            'name' => $this->data->name,
            'companies_id' => $this->data->companies_id,
            'apps_id' => $this->data->apps_id,
            'regions_id' => $this->data->regions_id,
        ], [
            'users_id' => $this->data->users_id,
            'location' => $this->data->location,
            'is_default' => $this->data->is_default,
            'is_published' => $this->data->is_published,
        ]);
    }
}
