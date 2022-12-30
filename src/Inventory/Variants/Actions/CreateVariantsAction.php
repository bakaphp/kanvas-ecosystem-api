<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Variants\Actions;

use Kanvas\Inventory\Variants\DataTransferObject\Variants as VariantsDto;
use Kanvas\Inventory\Variants\Models\Variants;

class CreateVariantsAction
{
    /**
     * __construct
     *
     * @return void
     */
    public function __construct(
        private VariantsDto $variantDto
    ) {
    }

    /**
     * execute
     *
     * @return Variants
     */
    public function execute(): Variants
    {
        return Variants::create($this->variantDto->toArray());
    }
}
