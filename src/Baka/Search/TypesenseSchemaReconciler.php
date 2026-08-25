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
        private readonly array $settings = [],
    ) {
    }

    public static function forApp(Apps $app): self
    {
        $settings = $app->get('typesense_search_settings') ?? [];

        return new self($app, SearchEngineResolver::getTypesenseClient($settings), $settings);
    }

    /**
     * Which collection, on which server. Apps often share one index, so a caller sweeping every app
     * needs this to avoid reconciling the same collection once per app.
     */
    public function target(Model $model): string
    {
        return (string) json_encode([
            $model->searchableAs(),
            $this->settings['typesense_api_key'] ?? config('scout.typesense.api_key'),
            $this->settings['typesense_nodes'] ?? config('scout.typesense.nodes'),
        ]);
    }

    /**
     * @return list<array{name: string, from: string, to: string, widening: bool}>
     */
    public function drift(Model $model, bool $wideningOnly = false): array
    {
        $live = $this->liveSchema($model->searchableAs());

        if ($live === []) {
            return [];
        }

        /** @var list<array{name: string, type: string}> $liveFields */
        $liveFields = $live['fields'] ?? [];

        // Keyed by name into a list, because a half-applied alter can leave the same field twice
        // under two types — collapsing to one would hide exactly the breakage worth repairing.
        $liveTypes = [];
        foreach ($liveFields as $liveField) {
            $liveTypes[$liveField['name']][] = $liveField['type'];
        }

        $protected = ['id', $live['default_sorting_field'] ?? null];
        $drift = [];

        foreach ($this->declaredFields($model) as $field) {
            $name = $field['name'] ?? null;
            $declared = $field['type'] ?? null;

            if ($name === null || $declared === null || in_array($name, $protected, true)) {
                continue;
            }

            $current = $liveTypes[$name] ?? null;

            // A field the collection doesn't have yet is auto-typed on the next document that
            // carries it, so there is nothing to re-type — adding it is a separate concern.
            if ($current === null || $current === [$declared]) {
                continue;
            }

            $widening = $this->isWidening($current, $declared);

            if ($wideningOnly && ! $widening) {
                continue;
            }

            $drift[] = [
                'name' => $name,
                'from' => implode(' + ', $current),
                'to' => $declared,
                'widening' => $widening,
            ];
        }

        return $drift;
    }

    /**
     * @return array{altered: list<array<string, mixed>>, failed: list<array<string, mixed>>}
     */
    public function reconcile(Model $model, bool $wideningOnly = true): array
    {
        $drift = $this->drift($model, $wideningOnly);
        $collection = $model->searchableAs();
        $declaredFields = array_column($this->declaredFields($model), null, 'name');

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
     * @return list<array{name?: string, type?: string}>
     */
    private function declaredFields(Model $model): array
    {
        return $model->typesenseCollectionSchema()['fields'] ?? [];
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
