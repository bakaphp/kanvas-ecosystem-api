<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Products;

use Kanvas\Inventory\Products\Actions\CreateProductAction;
use Kanvas\Inventory\Products\DataTransferObject\Product as ProductDto;
use Kanvas\Inventory\Products\Models\Products as ProductsModel;
use Kanvas\Inventory\ProductsTypes\Repositories\ProductsTypesRepository;
use Kanvas\Inventory\Products\Repositories\ProductsRepository;

class Products
{
    /**
     * create
     *
     * @param  mixed $root
     * @param  array $req
     * @return ProductsModel
     */
    public function create(mixed $root, array $req): ProductsModel
    {
        if (key_exists('products_types_id', $req)) {
            $productType = ProductsTypesRepository::getById($req['products_types_id']);
        }
        $productDto = ProductDto::from([
            'products_types_id' => isset($productType) ? $productType->id : null,
            'name' => $req['input']['name'],
            'description' => $req['input']['description'],
            'short_description' => $req['input']['short_description'] ?? null,
            'html_description' => $req['input']['html_description'] ?? null,
            'warranty_terms' => $req['input']['warranty_terms'] ?? null,
            'upc' => $req['input']['upc'] ?? null,
            'categories' => $req['input']['categories'] ?? [],
            'warehouses' => $req['input']['warehouses'] ?? [],
        ]);
        $action = new CreateProductAction($productDto);
        return $action->execute();
    }

    /**
     * update
     *
     * @param  mixed $root
     * @param  array $req
     * @return ProductsModel
     */
    public function update(mixed $root, array $req): ProductsModel
    {
        $product = ProductsRepository::getById($req['id']);
        $product->update($req['input']);
        return $product;
    }

    /**
     * delete
     *
     * @param  mixed $root
     * @param  array $req
     * @return bool
     */
    public function delete(mixed $root, array $req): bool
    {
        $product = ProductsRepository::getById($req['id']);
        return $product->delete();
    }
}
