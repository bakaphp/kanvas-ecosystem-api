<?php

declare(strict_types=1);

namespace Tests\GraphQL\Inventory;

use Kanvas\Inventory\Status\Models\Status;
use Tests\TestCase;

class StatusTest extends TestCase
{
    public function testTypesenseSchemaIdIsString(): void
    {
        $schema = new Status()->typesenseCollectionSchema();
        $idField = collect($schema['fields'])->firstWhere('name', 'id');
        $this->assertNotNull($idField);
        $this->assertSame('string', $idField['type'], 'Typesense requires the document id field to be a string');
    }
}
