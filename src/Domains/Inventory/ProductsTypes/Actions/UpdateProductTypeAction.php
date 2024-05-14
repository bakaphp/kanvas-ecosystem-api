<?php

declare(strict_types=1);

namespace Kanvas\Inventory\ProductsTypes\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Repositories\CompaniesRepository;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;

class UpdateProductTypeAction
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        protected ProductsTypes $productType,
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

        $this->productType->name = $this->data->name;
        $this->productType->description = $this->data->description;
        $this->productType->weight = $this->data->weight;
        $this->productType->saveOrFail();

        return $this->productType;
    }
}
