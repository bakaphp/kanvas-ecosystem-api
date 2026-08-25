<?php

declare(strict_types=1);

namespace Baka\Search;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * A collection is only ever created from typesenseCollectionSchema() — Scout never revisits it — so
 * a field the model declares today keeps whatever type the live collection was born with. Fields
 * Typesense auto-typed from the first document it saw are the ones that bite: json_encode drops the
 * zero fraction, so a float 100.00 goes over the wire as `100`, the field locks to int64, and every
 * later 99.99 is rejected for the life of the collection (Sentry KANVAS-ECOSYSTEM-628).
 *
 * Typesense can re-type a field in place (drop + re-add, re-indexed from the stored documents),
 * which is what this reconciles.
 */
class TypesenseSchemaReconciler
{
    /**
     * Widening only: every value already indexed as an integer survives as a float, so this is safe
     * to run against a live collection. Anything else is a lossy re-type and stays opt-in.
     */
    private const WIDENING = [
        'int32' => 'float',
        'int64' => 'float',
        'int32[]' => 'float[]',
        'int64[]' => 'float[]',
    ];

    public function __construct(
        private readonly Apps $app,
        private readonly TypesenseClient $client,
    ) {
    }

    public static function forApp(Apps $app): self
    {
        return new self(
            $app,
            SearchEngineResolver::getTypesenseClient($app->get('typesense_search_settings') ?? []),
        );
    }

    /**
     * @return list<array{name: string, from: string, to: string, widening: bool}>
     */
    public function drift(Model $model): array
    {
        $live = $this->liveSchema($model->searchableAs());

        if ($live === []) {
            return [];
        }

        // Keyed by name into a list, because a half-applied alter can leave the same field twice
        // under two types — collapsing to one would hide exactly the breakage worth repairing.
        $liveTypes = [];
        foreach ((array) ($live['fields'] ?? []) as $liveField) {
            $liveField = (array) $liveField;
            $liveTypes[(string) $liveField['name']][] = (string) $liveField['type'];
        }

        $protected = ['id', $live['default_sorting_field'] ?? null];
        $drift = [];

        foreach ((array) ($model->typesenseCollectionSchema()['fields'] ?? []) as $field) {
            $field = (array) $field;
            $name = isset($field['name']) ? (string) $field['name'] : null;
            $declared = isset($field['type']) ? (string) $field['type'] : null;

            if ($name === null || $declared === null || in_array($name, $protected, true)) {
                continue;
            }

            $current = $liveTypes[$name] ?? null;

            // A field the collection doesn't have yet is auto-typed on the next document that
            // carries it, so there is nothing to re-type — adding it is a separate concern.
            if ($current === null || $current === [$declared]) {
                continue;
            }

            $drift[] = [
                'name' => $name,
                'from' => implode(' + ', $current),
                'to' => $declared,
                'widening' => $this->isWidening($current, $declared),
            ];
        }

        return $drift;
    }

    /**
     * @return array{altered: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function reconcile(Model $model, bool $wideningOnly = true): array
    {
        $drift = $this->drift($model);

        if ($wideningOnly) {
            $drift = array_values(array_filter($drift, fn (array $field) => $field['widening']));
        }

        $collection = $model->searchableAs();
        $declaredFields = array_column($model->typesenseCollectionSchema()['fields'] ?? [], null, 'name');

        $altered = [];
        $failed = [];

        foreach ($drift as $field) {
            // One field per request. Batching them corrupts the schema whenever two names share a
            // prefix — re-typing `items.quantity` and `items.quantity_fulfilled` together answers
            // "There are duplicate field names in the schema" and leaves the second one registered
            // twice, under both the old and the new type.
            try {
                $this->client->getCollections()[$collection]->update([
                    'fields' => [
                        ['name' => $field['name'], 'drop' => true],
                        $declaredFields[$field['name']],
                    ],
                ]);

                $altered[] = $field;
            } catch (Throwable $e) {
                $failed[] = $field + ['error' => $e->getMessage()];
            }
        }

        if ($altered !== []) {
            Cache::forget('typesense_collection_schema_' . $this->app->getId() . '_' . $collection);
        }

        return ['altered' => $altered, 'failed' => $failed];
    }

    /**
     * @param list<string> $current
     */
    private function isWidening(array $current, string $declared): bool
    {
        foreach ($current as $type) {
            if ($type !== $declared && (self::WIDENING[$type] ?? null) !== $declared) {
                return false;
            }
        }

        return true;
    }

    private function liveSchema(string $collection): array
    {
        try {
            return $this->client->getCollections()[$collection]->retrieve();
        } catch (Throwable) {
            // Collection not created yet, or the server is unreachable — nothing to reconcile.
            return [];
        }
    }
}
