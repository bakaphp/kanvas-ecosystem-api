<?php

declare(strict_types=1);

namespace Tests\Inventory;

use Kanvas\Inventory\Variants\Models\Variants;
use Tests\TestCase;

class VariantTypesenseSchemaTest extends TestCase
{
    public function testEmptyAndNullableFieldsAreOptional(): void
    {
        $schema = new Variants()->typesenseCollectionSchema();
        $fields = collect($schema['fields'])->keyBy('name');

        foreach (['files', 'ean', 'barcode', 'attributes'] as $field) {
            $this->assertTrue($fields[$field]['optional']);
        }
    }
}
