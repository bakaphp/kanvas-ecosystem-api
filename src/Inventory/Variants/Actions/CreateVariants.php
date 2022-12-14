<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants;

class CreateVariants {
    public function __construct(
        private VariantsDto $variantDto
    ){
    }

    public function execute()
    {
        Variants::create($this->variantDto->toArray());
    }
}