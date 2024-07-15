<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\ProductsTypes;

use Kanvas\Inventory\ProductsTypes\Actions\CreateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\Actions\UpdateProductTypeAction;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ProductsTypesModel;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Kanvas\Inventory\ProductsTypes\Services\ProductTypeService;

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

        $productType = (new CreateProductTypeAction(
            ProductsTypesDto::viaRequest($request, $user, $company),
            $user
        ))->execute();

        if (isset($request['products_attributes'])) {
            ProductTypeService::addAttributes(
                $productType,
                auth()->user(),
                $request['products_attributes']
            );
        }

        if (isset($request['variants_attributes'])) {
            ProductTypeService::addAttributes(
                $productType,
                auth()->user(),
                $request['variants_attributes'],
                true
            );
        }

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
        $productType = ProductsTypesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        (new UpdateProductTypeAction(
            $productType,
            ProductsTypesDto::viaRequest($request['input'], $user, $company),
            $user
        ))->execute();

        if (isset($request['input']['products_attributes'])) {
            $productType->productsTypesAttributes()->where('to_variant', 0)->delete();
            ProductTypeService::addAttributes(
                $productType,
                auth()->user(),
                $request['input']['products_attributes'],
            );
        }

        if (isset($request['input']['variants_attributes'])) {
            $productType->productsTypesAttributes()->where('to_variant', 1)->delete();
            ProductTypeService::addAttributes(
                $productType,
                auth()->user(),
                $request['input']['variants_attributes'],
                true
            );
        }

        return $productType;
    }

    /**
     * Assign attributes to products types
     *
     * @param mixed $root
     * @param array $request
     * @return ProductsTypesModel
     */
    public function assignAttributes(mixed $root, array $request): ProductsTypesModel
    {
        $productType = ProductsTypesRepository::getById((int) $request['id'], auth()->user()->getCurrentCompany());

        if (isset($request['input']['products_attributes'])) {
            $productType->productsTypesAttributes()->where('to_variants', 0)->delete();
            $productType->addAttributes(auth()->user(), $request['input']['products_attributes']);
        }

        if (isset($request['input']['variants_attributes'])) {
            $productType->productsTypesAttributes()->where('to_variants', 1)->delete();
            $productType->addAttributes(auth()->user(), $request['input']['variants_attributes'], true);
        }

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
