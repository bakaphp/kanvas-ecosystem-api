<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Attributes\Models\AttributesValues;

class AddAttributeValue
{
    public function __construct(
        protected Attributes $attributeModel,
        protected array $values
    ) {
    }

    public function execute(): void
    {
        foreach ($this->values as $value) {
            AttributesValues::firstOrCreate([
                'attributes_id' => $this->attributeModel->getId(),
                'value' => $value['value'],
            ]);
        }
    }
}
