<?php

declare(strict_types=1);

namespace Tests\GraphQL\Workflow;

use Kanvas\Workflow\Models\ReceiverWebhook;
use Tests\TestCase;

class ReceiverWebhookSearchTest extends TestCase
{
    public function testTypesenseSchemaIdIsString(): void
    {
        $schema = new ReceiverWebhook()->typesenseCollectionSchema();
        $idField = collect($schema['fields'])->firstWhere('name', 'id');
        $this->assertNotNull($idField);
        $this->assertSame('string', $idField['type'], 'Typesense requires the document id field to be a string');
    }
}
