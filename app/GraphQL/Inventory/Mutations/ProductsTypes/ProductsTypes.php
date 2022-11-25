<?php
declare(strict_types=1);
namespace App\GraphQL\Inventory\Mutations\ProductsTypes;

use Kanvas\Inventory\ProductsTypes\Actions\CreateProductType;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;
use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes as ProductsTypesModel;

class ProductsTypes
{
    public function create(mixed $root, array $request): ProductsTypesModel
    {
        $dto = ProductsTypesDto::fromArray($request['input']);
        $productType = (new CreateProductType($dto))->execute();
        return $productType;
    }
}
