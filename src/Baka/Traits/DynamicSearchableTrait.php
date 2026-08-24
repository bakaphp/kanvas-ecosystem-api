<?php

declare(strict_types=1);

namespace Baka\Traits;

use BadMethodCallException;
use Baka\Search\RecordSizeTrimmer;
use Baka\Search\SearchEngineResolver;
use Baka\Search\TypesenseCollectionInspector;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Engines\TypesenseEngine;
use Laravel\Scout\Searchable;

trait DynamicSearchableTrait
{
    use Searchable;

    protected bool $isTypesense = false;

    protected bool $isAlgolia = false;

    public function searchableUsing()
    {
        $engine = app(SearchEngineResolver::class)->resolveEngine($this, $this->app);

        if ($engine instanceof TypesenseEngine) {
            $this->setTypesense();
        }

        return $engine;
    }

    public function setTypesense(bool $isTypesense = true): void
    {
        $this->isTypesense = $isTypesense;
    }

    public function setAlgolia(bool $isAlgolia = true): void
    {
        $this->isAlgolia = $isAlgolia;
    }

    public function isTypesense(): bool
    {
        if ($this->isTypesense) {
            return true;
        }

        return $this->resolvedEngineName() === 'typesense';
    }

    public function isAlgolia(): bool
    {
        if ($this->isAlgolia) {
            return true;
        }

        return $this->resolvedEngineName() === 'algolia';
    }

    public function searchIndexRejectsObjectField(string $field): bool
    {
        if (! $this->isTypesense()) {
            return false;
        }

        $app = $this->app ?? app(Apps::class);

        return TypesenseCollectionInspector::rejectsObjectField(
            $app,
            $this->searchableAs(),
            $field
        );
    }

    /**
     * Byte budget a record must fit in before the model's trimming cascade kicks in.
     * Resolution order: per-app setting → scout config → 9500 (Algolia's 10k cap minus headroom).
     */
    public function algoliaRecordSizeLimit(): int
    {
        $app = $this->app ?? app(Apps::class);

        $limit = (int) ($app->get('algolia_record_size_limit')
            ?? config('scout.algolia.record_size_limit', 9500));

        return $limit > 0 ? $limit : 9500;
    }

    /**
     * Entry point for a model's trimming cascade — see RecordSizeTrimmer.
     */
    public function trimToAlgoliaLimit(array $record): RecordSizeTrimmer
    {
        return RecordSizeTrimmer::make(
            $record,
            $this->algoliaRecordSizeLimit()
        );
    }

    protected function resolvedEngineName(): string
    {
        try {
            $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        } catch (BadMethodCallException $e) {
            $model = $this;
        }

        $app = $model->app ?? app(Apps::class);

        // config('scout.driver') is an explicit null when SCOUT_DRIVER=null, so the third arg
        // default never fires — coalesce to 'null' (→ NullEngine) so this never returns null and
        // TypeErrors the ": string" return on apps with no engine configured.
        $defaultEngine = $app->get('search_engine') ?? config('scout.driver');
        $modelSpecificEngine = $app->get($this->getTable() . '_search_engine') ?? null;

        return $modelSpecificEngine ?? $defaultEngine ?? 'null';
    }

    /**
     * Fallback Typesense collection schema for models that don't declare their own.
     *
     * Scout hands this straight to Typesense's create-collection call. With neither this method
     * nor a `scout.typesense.model-settings.*.collection-schema` entry, Scout sends `[]` and
     * Typesense rejects it with "Parameter `fields` is required", killing the indexing job
     */
    public function typesenseCollectionSchema(): array
    {
        return [
            'name' => $this->searchableAs(),
            'fields' => [
                [
                    'name' => '.*',
                    'type' => 'auto',
                ],
            ],
            'enable_nested_fields' => true,
        ];
    }

    public function getRelations(?string $modelClass = null): array
    {
        return func_num_args() > 0 ? [] : $this->relations;
    }
}
