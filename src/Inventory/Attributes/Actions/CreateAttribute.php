<?php
declare(strict_types=1);
namespace Kanvas\Inventory\Attributes\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\DataTransferObject\Attributes as AttributeDto;

class CreateAttribute
{
    public function __construct(
        protected AttributeDto $dto
    ) {
    }

    /**
     * execute
     *
     * @return Attributes
     */
    public function execute() : Attributes
    {
        $attribute = new Attributes();
        $attribute->name = $this->dto->name;
        $attribute->saveOrFail();

        return $attribute;
    }
}
