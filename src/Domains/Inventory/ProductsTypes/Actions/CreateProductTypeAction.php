<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class CreateProductTypeAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected ProductsTypesDto $data,
        protected UserInterface $user
    ) {
    }

    /**
     * execute.
     *
     * @return ProductsTypes
     */
    public function execute(): ProductsTypes
    {
        CompaniesRepository::userAssociatedToCompany(
            $this->data->company,
            $this->user
        );

        return ProductsTypes::firstOrCreate([
            'companies_id' => $this->data->company->getId(),
            'slug' => $this->dto->slug ?? Str::slug($this->data->name),
            'apps_id' => app(Apps::class)->getId(),
        ], [
            'name' => $this->data->name,
            'description' => $this->data->description,
            'weight' => $this->data->weight,
            'users_id' => $this->user->getId(),
            'is_published' => $this->data->isPublished
        ]);
    }
}
