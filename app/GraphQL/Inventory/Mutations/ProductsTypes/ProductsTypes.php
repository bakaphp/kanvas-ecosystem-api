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
        $request = $request['input'];

        $user = auth()->user();
        $company = $user->getCurrentCompany();
        if (! $user->isAppOwner()) {
            unset($request['companies_id']);
        }

        return (new CreateProductTypeAction(
            ProductsTypesDto::viaRequest($request, $user, $company),
            $user
        ))->execute();
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
        $productType = ProductsTypesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
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
        $productType = ProductsTypesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        return $productType->delete();
    }
}
