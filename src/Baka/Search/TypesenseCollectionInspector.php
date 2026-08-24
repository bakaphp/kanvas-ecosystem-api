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
                    $client = SearchEngineResolver::getTypesenseClient($app->get('typesense_search_settings') ?? []);

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
