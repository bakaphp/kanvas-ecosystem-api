<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Warehouses\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Exceptions\ValidationException;
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
    public function execute(): Warehouses
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->data->company,
            $this->user
        );

        try {
            $warehouse = Warehouses::firstOrCreate([
                'name' => $this->data->name,
                'companies_id' => $this->data->company->getId(),
                'apps_id' => $this->data->app->getId(),
                'regions_id' => $this->data->region->getId(),
            ], [
                'users_id' => $this->data->user->getId(),
                'location' => $this->data->location,
                'is_default' => $this->data->is_default,
                'is_published' => $this->data->is_published,
            ]);
        } catch (ValidationException $e) {
            throw new ValidationException($e->getMessage());
        }

        return $warehouse;
    }
}
