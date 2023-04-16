<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\RemoveAttributeAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;

class Products
{
    /**
     * create.
     */
    public function create(mixed $root, array $req): ProductsModel
    {
        $productDto = ProductDto::viaRequest($req['input']);
        $action = new CreateProductAction($productDto, auth()->user());

        return $action->execute();
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $product->update($req['input']);

        return $product;
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $req): bool
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());

        return $product->softDelete();
    }

    /**
     * addAttribute.
     */
    public function addAttribute(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $attribute = AttributesRepository::getById($req['attribute_id'], auth()->user()->getCurrentCompany());
        $action = new AddAttributeAction($product, $attribute, $req['value']);

        return $action->execute();
    }

    /**
     * removeAttribute.
     */
    public function removeAttribute(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $attribute = AttributesRepository::getById($req['attribute_id'], auth()->user()->getCurrentCompany());
        $action = new RemoveAttributeAction($product, $attribute);

        return $action->execute();
    }

    /**
     * addWarehouse.
     */
    public function addWarehouse(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $product->warehouses()->attach($req['warehouse_id']);

        return $product;
    }

    /**
     * removeWarehouse.
     */
    public function removeWarehouse(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $product->warehouses()->detach($req['warehouse_id']);

        return $product;
    }

    /**
     * addCategory.
     */
    public function addCategory(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $product->categories()->attach($req['category_id']);

        return $product;
    }

    /**
     * removeCategory.
     */
    public function removeCategory(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id'], auth()->user()->getCurrentCompany());
        $product->categories()->detach($req['category_id']);

        return $product;
    }
}
