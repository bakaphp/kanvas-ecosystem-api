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

    /**
     * Typesense 400s with "Could not find a field named `x` in the schema" when query_by names a
     * field the collection doesn't have, and rejects any query_by target that isn't string-typed —
     * so a stale query_by silently kills search for every tenant on Typesense while Algolia (which
     * ignores query_by entirely) keeps working, hiding the break.
     */
    public function testEveryQueryByFieldExistsInTheCollectionSchemaAsAString(): void
    {
        $failures = [];

        foreach ($this->searchableModels() as $class) {
            $model = new $class();
            $model->setRelation('app', app(Apps::class));

            // Models that centralise the list expose it as a method; the rest still inline it in search().
            if (method_exists($model, 'searchQueryBy')) {
                $matches = [1 => [$model->searchQueryBy()]];
            } else {
                $contents = file_get_contents(new ReflectionClass($class)->getFileName());

                if (! preg_match_all("/'query_by'\s*=>\s*'([^']+)'/", $contents, $matches)) {
                    continue;
                }
            }

            $fields = [];
            foreach ($model->typesenseCollectionSchema()['fields'] ?? [] as $field) {
                $fields[$field['name']] = $field['type'];
            }

            if (isset($fields['.*'])) {
                continue; // auto-schema matches anything
            }

            foreach ($matches[1] as $queryBy) {
                foreach (array_map('trim', explode(',', $queryBy)) as $name) {
                    $type = $fields[$name] ?? null;

                    if ($type === null) {
                        $failures[] = $class . " query_by names '{$name}', absent from typesenseCollectionSchema()";
                    } elseif (! in_array($type, ['string', 'string[]'], true)) {
                        $failures[] = $class . " query_by names '{$name}' typed {$type}; must be string or string[]";
                    }
                }
            }
        }

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
