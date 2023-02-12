<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\ProductsTypes;

use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ProductsTypesModel;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;

class ProductsTypes
{
    /**
     * create.
     *
     * @param  mixed $root
     * @param  array $request
     *
     * @return ProductsTypesModel
     */
    public function create(mixed $root, array $request): ProductsTypesModel
    {
        $dto = ProductsTypesDto::viaRequest($request['input']);
        $productType = (
            new CreateProductTypeAction(
                $dto,
                auth()->user()
            ))->execute();
        return $productType;
    }

    /**
     * update.
     *
     * @param  mixed $root
     * @param  array $request
     *
     * @return ProductsTypesModel
     */
    public function update(mixed $root, array $request): ProductsTypesModel
    {
        $productType = ProductsTypesRepository::getById($request['id'], auth()->user()->getCurrentCompany());
        $productType->update($request['input']);
        return $productType;
    }

    /**
     * delete.
     *
     * @param  mixed $root
     * @param  array $request
     *
     * @return bool
     */
    public function delete(mixed $root, array $request): bool
    {
        $productType = ProductsTypesRepository::getById($request['id'], auth()->user()->getCurrentCompany());
        return $productType->delete();
    }
}
