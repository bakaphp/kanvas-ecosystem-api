<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\Models\AttributesValues;

class AddAttributeValue
{
    public function __construct(
        protected Attributes $attributeModel,
        protected mixed $value
    ) {
    }

    /**
     * execute.
     *
     * @return Attributes
     */
    public function execute(): AttributesValues
    {
        return AttributesValues::firstOrCreate([
            'attributes_id' => $this->attributeModel->getId(),
            'value' => $this->value,
        ]);
    }
}
