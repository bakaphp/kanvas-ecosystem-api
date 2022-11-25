<?php
declare(strict_types=1);
namespace Kanvas\Inventory\ProductsTypes\Actions;

use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;

class CreateProductType
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        protected ProductsTypesDto $dto
    ) {
    }

    /**
     * execute
     *
     * @return ProductsTypes
     */
    public function execute(): ProductsTypes
    {
        return ProductsTypes::create([
            'name' => $this->dto->name,
            'description' => $this->dto->description,
            'weight' => $this->dto->weight,
        ]);
    }
}
