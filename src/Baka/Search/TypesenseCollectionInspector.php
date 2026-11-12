<?php

declare(strict_types=1);

namespace Baka\Search;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Throwable;

/**
 * Collections created before a model declared its own schema were auto-typed from their first
 * document, and Typesense can't re-type a field — so a field the model now declares as `object`
 * can be locked to `string` on the server, failing every import batch (Sentry KANVAS-ECOSYSTEM-628).
 * Documents have to be shaped to what the server already holds; this reads it, cached.
 */
class TypesenseCollectionInspector
{
    private const CACHE_TTL = 3600;

    public static function schema(Apps $app, string $collection): array
    {
        return Cache::remember(
            'typesense_collection_schema_' . $app->getId() . '_' . $collection,
            self::CACHE_TTL,
            function () use ($app, $collection): array {
                try {
                    $client = SearchEngineResolver::typesenseClient($app);

                    return $client->getCollections()->{$collection}->retrieve();
                } catch (Throwable) {
                    // Not created yet or server unreachable — callers keep the model-declared shape.
                    return [];
                }
            }
        );
    }

    public static function fieldType(Apps $app, string $collection, string $field): ?string
    {
        $fields = self::schema($app, $collection)['fields'] ?? [];

        return array_column($fields, 'type', 'name')[$field] ?? null;
    }

    /**
     * One query_by field the live collection never got 404s the whole search ("Could not find a
     * field named `x` in the schema"), so those are dropped until
     * kanvas:search:typesense-sync-schema adds them. Unknown schema or no field left: unchanged.
     */
    public static function queryableFields(Apps $app, string $collection, string $queryBy): string
    {
        $liveFields = array_column(self::schema($app, $collection)['fields'] ?? [], 'name');
        $queryable = array_intersect(explode(',', $queryBy), $liveFields);

        return $queryable === [] ? $queryBy : implode(',', $queryable);
    }

    public static function rejectsObjectField(Apps $app, string $collection, string $field): bool
    {
        $schema = self::schema($app, $collection);

        if ($schema === []) {
            return false;
        }

        if (($schema['enable_nested_fields'] ?? true) === false) {
            return true;
        }

        return self::fieldType($app, $collection, $field) === 'string';
    }
}
