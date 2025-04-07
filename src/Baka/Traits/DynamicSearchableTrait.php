<?php

declare(strict_types=1);

namespace Baka\Traits;

use BadMethodCallException;
use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Engines\TypesenseEngine;
use Laravel\Scout\Searchable;

trait DynamicSearchableTrait
{
    use Searchable;
    protected bool $isTypesense = false;

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

    public function isTypesense(): bool
    {
        if ($this->isTypesense) {
            return true;
        }

        try {
            $model = ! $this->searchableDeleteRecord() ? $this : $this->withTrashed()->find($this->id);
        } catch (BadMethodCallException $e) {
            $model = $this;
        }

        $app = $model->app ?? app(Apps::class);

        $defaultEngine = $app->get('search_engine') ?? config('scout.driver', 'algolia');
        // If there's a model, try to get model-specific engine setting
        $modelSpecificEngine = $app->get($this->getTable() . '_search_engine') ?? null;
        // Use model-specific engine if available, otherwise use default
        $engine = $modelSpecificEngine ?? $defaultEngine;

        return $engine === 'typesense';
    }
}
