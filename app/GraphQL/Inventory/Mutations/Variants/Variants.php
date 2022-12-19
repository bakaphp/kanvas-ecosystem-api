<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\Variants;

use Kanvas\Inventory\Variants\Actions\CreateVariantsAction;
use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantDto;
use Kanvas\Inventory\Variants\Models\Variants as VariantModel;
use Kanvas\Inventory\Variants\Repositories\VariantsRepository;

class Variants
{
    /**
     * create
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function create(mixed $root, array $req): VariantModel
    {
        $variantDto = VariantDto::from([
            'products_id' => $req['input']['products_id'],
            'name' => $req['input']['name'],
            'description' => $req['input']['description'] ?? '',
            'short_description' => $req['input']['short_description'] ?? null,
            'html_description' => $req['input']['html_description'] ?? null,
            'warranty_terms' => $req['input']['warranty_terms'] ?? null,
            'upc' => $req['input']['upc'] ?? null,
            'categories' => $req['input']['categories'] ?? [],
            'warehouses' => $req['input']['warehouses'] ?? [],
        ]);
        $action = new CreateVariantsAction($variantDto);
        return $action->execute();
    }

    /**
     * update
     *
     * @param  mixed $root
     * @param  array $req
     * @return VariantModel
     */
    public function update(mixed $root, array $req): VariantModel
    {
        $variant = VariantsRepository::getById($req['id']);
        $variant->update($req['input']);
        return $variant;
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
        $variant = VariantsRepository::getById($req['id']);
        return $variant->delete();
    }
}
