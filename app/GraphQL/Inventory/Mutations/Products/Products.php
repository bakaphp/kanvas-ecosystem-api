<?php

declare(strict_types=1);

namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Attributes\Repositories\AttributesRepository;
use Kanvas\Inventory\Products\Actions\AddAttributeAction;
use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\Actions\RemoveAttributeAction;
use Kanvas\Inventory\Products\Actions\UpdateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;
use Kanvas\Inventory\Status\Repositories\StatusRepository;

class Products
{
    /**
     * create.
     * @todo allow to search only companies with access to the app
     */
    public function create(mixed $root, array $req): ProductsModel
    {
        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById(
                (int) $req['input']['status']['id'],
                auth()->user()->getCurrentCompany()
            )->getId();
        }

        if (auth()->user()->isAppOwner() && isset($req['input']['company_id'])) {
            $company = Companies::getById($req['input']['company_id']);
        } else {
            $company = auth()->user()->getCurrentCompany();
        }

        $productDto = ProductDto::viaRequest($req['input'], $company);
        $action = new CreateProductAction($productDto, auth()->user());

        return $action->execute();
    }

    /**
     * update.
     */
    public function update(mixed $root, array $req): ProductsModel
    {
        $company = auth()->user()->getCurrentCompany();

        if (isset($req['input']['status'])) {
            $req['input']['status_id'] = StatusRepository::getById((int) $req['input']['status']['id'], $company)->getId();
        }

        $product = ProductsRepository::getById((int) $req['id'], $company);
        $productDto = ProductDto::viaRequest($req['input'], $product->company);
        $action = new UpdateProductAction($product, $productDto, auth()->user());

        return $action->execute();
    }

    /**
     * delete.
     */
    public function delete(mixed $root, array $req): bool
    {
        $product = ProductsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());

        return $product->delete();
    }

    /**
     * addAttribute.
     */
    public function addAttribute(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $attribute = AttributesRepository::getById((int) $req['attribute_id'], auth()->user()->getCurrentCompany());
        $action = new AddAttributeAction($product, $attribute, $req['value']);

        return $action->execute();
    }

    /**
     * removeAttribute.
     */
    public function removeAttribute(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $attribute = AttributesRepository::getById((int) $req['attribute_id'], auth()->user()->getCurrentCompany());
        $action = new RemoveAttributeAction($product, $attribute);

        return $action->execute();
    }

    /**
     * addWarehouse.
     */
    public function addWarehouse(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $product->warehouses()->attach($req['warehouse_id']);

        return $product;
    }

    /**
     * removeWarehouse.
     */
    public function removeWarehouse(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $product->warehouses()->detach($req['warehouse_id']);

        return $product;
    }

    /**
     * addCategory.
     */
    public function addCategory(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $product->categories()->attach($req['category_id']);

        return $product;
    }

    /**
     * removeCategory.
     */
    public function removeCategory(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById((int) $req['id'], auth()->user()->getCurrentCompany());
        $product->categories()->detach($req['category_id']);

        return $product;
    }
}
