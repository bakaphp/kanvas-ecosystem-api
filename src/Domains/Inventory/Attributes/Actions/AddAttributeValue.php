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
        $currentLocale = app()->getLocale();

        foreach ($this->values as $value) {
            $attributeValue = AttributesValues::firstOrNewTranslatable([
                'attributes_id' => $this->attributeModel->getId(),
                'parent_id' => $value['parent_id'] ?? null,
            ], 'value', $value['value'], $currentLocale);

            if (!$attributeValue->exists && !empty($value['parent_id'])) {
                $parent = AttributesValues::getById($value['parent_id']);
                if ($parent) {
                    $attributeValue->parent()->associate($parent);
                    $parent->setHasChildren(true);
                }
            }

            if (!$attributeValue->exists) {
                $attributeValue->save();
            }
        }
    }
}
