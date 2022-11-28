<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\ProductsTypes;

use Kanvas\Inventory\ProductsTypes\Actions\CreateProductType;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ProductsTypesModel;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;

class ProductsTypes
{
    /**
     * create
     *
     * @param  mixed $root
     * @param  array $request
     * @return ProductsTypesModel
     */
    public function create(mixed $root, array $request): ProductsTypesModel
    {
        $dto = ProductsTypesDto::fromArray($request['input']);
        $productType = (new CreateProductType($dto))->execute();
        return $productType;
    }

    /**
     * update
     *
     * @param  mixed $root
     * @param  array $request
     * @return ProductsTypesModel
     */
    public function update(mixed $root, array $request): ProductsTypesModel
    {
        $productType = ProductsTypesRepository::getById($request['id']);
        $productType->update($request['input']);
        return $productType;
    }

    /**
     * delete
     *
     * @param  mixed $root
     * @param  array $request
     * @return bool
     */
    public function delete(mixed $root, array $request): bool
    {
        $productType = ProductsTypesRepository::getById($request['id']);
        return $productType->delete();
    }
}
