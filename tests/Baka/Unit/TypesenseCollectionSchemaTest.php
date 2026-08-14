<?php

declare(strict_types=1);

namespace Tests\Baka\Unit;

use Baka\Traits\DynamicSearchableTrait;
use FilesystemIterator;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Tests\TestCase;
use Throwable;

/**
 * Coverage ratchet for Typesense collection schemas.
 *
 * Scout creates a missing collection from whatever typesenseCollectionSchema() returns. An empty
 * schema makes Typesense answer "Parameter `fields` is required" and the indexing job dies —
 * Sentry KANVAS-ECOSYSTEM-62F, 377 events from Inventory attributes on app 26. Every searchable
 * model must therefore produce a schema with a name and at least one field.
 */
final class TypesenseCollectionSchemaTest extends TestCase
{
    public function testEverySearchableModelProducesANonEmptyCollectionSchema(): void
    {
        $failures = [];
        $checked = 0;

        foreach ($this->searchableModels() as $class) {
            $model = new $class();
            $model->setRelation('app', app(Apps::class));

            try {
                $schema = $model->typesenseCollectionSchema();
            } catch (Throwable $e) {
                $failures[] = $class . ' threw ' . $e::class . ': ' . $e->getMessage();

                continue;
            }

            $checked++;

            if (empty($schema['name'])) {
                $failures[] = $class . ' returns a schema with no collection name';
            }

            if (empty($schema['fields'])) {
                $failures[] = $class . ' returns a schema with no fields — Typesense rejects it';

                continue;
            }

            foreach ($schema['fields'] as $field) {
                if (empty($field['name']) || empty($field['type'])) {
                    $failures[] = $class . ' has a field missing name or type';
                }
            }
        }

        // Floor guards the scanner itself — a silently-broken discovery would pass on 0 models.
        $this->assertGreaterThan(20, $checked, 'Searchable model discovery looks broken');
        $this->assertSame([], $failures, implode(PHP_EOL, $failures));
    }

    public function testModelWithoutAnExplicitSchemaFallsBackToAutoFields(): void
    {
        $model = new class () extends Model {
            use DynamicSearchableTrait;

            public function searchableAs(): string
            {
                return 'fallback_index';
            }
        };

        $schema = $model->typesenseCollectionSchema();

        $this->assertSame('fallback_index', $schema['name']);
        $this->assertSame([['name' => '.*', 'type' => 'auto']], $schema['fields']);
    }

    /**
     * @return list<class-string<Model>>
     */
    private function searchableModels(): array
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(base_path('src'), FilesystemIterator::SKIP_DOTS)
        );

        $models = [];

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if (! str_contains($contents, 'DynamicSearchableTrait')
                || ! preg_match('/^namespace\s+([^;]+);/m', $contents, $namespace)
                || ! preg_match('/^(?:final\s+)?class\s+(\w+)/m', $contents, $className)
            ) {
                continue;
            }

            $class = $namespace[1] . '\\' . $className[1];

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);

            if ($reflection->isAbstract() || ! $reflection->isSubclassOf(Model::class)) {
                continue;
            }

            $models[] = $class;
        }

        return $models;
    }
}
