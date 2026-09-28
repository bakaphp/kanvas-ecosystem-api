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

    /**
     * Regression for KANVAS-ECOSYSTEM-628: the schema declares `objectID` as a string, but the
     * indexed payload passed the raw int `$this->id`, so Typesense rejected the import with
     * "Field `objectID` must be a string." The payload must match the declared type.
     */
    public function testSearchableArrayObjectIdIsString(): void
    {
        $webhook = new ReceiverWebhook();
        $webhook->id = 903;
        $webhook->name = 'Test Webhook';
        $webhook->apps_id = 1;
        $webhook->companies_id = 0;

        $document = $webhook->toSearchableArray();

        $this->assertIsString($document['objectID']);
        $this->assertSame('903', $document['objectID']);
    }
}
