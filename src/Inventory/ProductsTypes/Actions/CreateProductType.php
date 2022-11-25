<?php
declare(strict_types=1);
namespace Kanvas\Inventory\ProductsTypes\Actions;

use Kanvas\Inventory\ProductsTypes\Models\ProductsTypes;
use Kanvas\Inventory\ProductsTypes\DataTransferObject\ProductsTypes as ProductsTypesDto;

class CreateProductType
{
    public function __construct(
       protected ProductsTypesDto $dto
    )
    {
    }

    public function execute() {
        
    }
}
