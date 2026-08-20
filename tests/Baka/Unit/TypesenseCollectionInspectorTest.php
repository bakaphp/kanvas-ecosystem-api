<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Search\TypesenseCollectionInspector;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Tests\TestCase;

final class TypesenseCollectionInspectorTest extends TestCase
{
    public function testReportsTheFieldTypeTheLiveCollectionDeclares(): void
    {
        $app = app(Apps::class);
        $this->fakeSchema($app, 'legacy_message_index', [
            'fields' => [
                ['name' => 'message', 'type' => 'string'],
                ['name' => 'user', 'type' => 'object'],
            ],
            'enable_nested_fields' => true,
        ]);

        $this->assertSame('string', TypesenseCollectionInspector::fieldType($app, 'legacy_message_index', 'message'));
        $this->assertSame('object', TypesenseCollectionInspector::fieldType($app, 'legacy_message_index', 'user'));
        $this->assertNull(TypesenseCollectionInspector::fieldType($app, 'legacy_message_index', 'nope'));

        $this->assertTrue(TypesenseCollectionInspector::rejectsObjectField($app, 'legacy_message_index', 'message'));
        $this->assertFalse(TypesenseCollectionInspector::rejectsObjectField($app, 'legacy_message_index', 'user'));
    }

    public function testACollectionWithoutNestedFieldsRejectsEveryObject(): void
    {
        $app = app(Apps::class);
        $this->fakeSchema($app, 'flat_message_index', [
            'fields' => [['name' => 'message', 'type' => 'auto']],
            'enable_nested_fields' => false,
        ]);

        $this->assertTrue(TypesenseCollectionInspector::rejectsObjectField($app, 'flat_message_index', 'message'));
    }

    public function testAnUnknownCollectionKeepsTheModelDeclaredShape(): void
    {
        $app = app(Apps::class);
        $this->fakeSchema($app, 'missing_message_index', []);

        $this->assertNull(TypesenseCollectionInspector::fieldType($app, 'missing_message_index', 'message'));
        $this->assertFalse(TypesenseCollectionInspector::rejectsObjectField($app, 'missing_message_index', 'message'));
    }

    private function fakeSchema(Apps $app, string $collection, array $schema): void
    {
        Cache::put('typesense_collection_schema_' . $app->getId() . '_' . $collection, $schema, 60);
    }
}
