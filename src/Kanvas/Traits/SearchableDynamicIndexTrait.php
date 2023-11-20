<?php

declare(strict_types=1);

namespace Kanvas\Traits;

use Kanvas\Apps\Models\Apps;
use Laravel\Scout\Searchable;

trait SearchableDynamicIndexTrait
{
    use Searchable;

    protected static ?string $overWriteSearchIndex = null;

    abstract public static function searchableIndex(): string;

    abstract public function shouldBeSearchable(): bool;

    /**
     * Get the name of the index associated with the model.
     */
    public function searchableAs(): string
    {
        $appId = $this->apps_id ?? app(Apps::class)->getId();
        $record = null;
        $companyId = $this->companies_id ?? null;

        if ($this->searchableDeleteRecord()) {
            $record = $this->find($this->id);
            $companyId = $record instanceof self ? $record->companies_id : $this->companies_id;
        }

        $indexName = $companyId === null && self::$overWriteSearchIndex !== null
            ? self::$overWriteSearchIndex
            : self::searchableIndex() . (string) $companyId;

        return config('scout.prefix') . 'app_' . $appId . '_' . $indexName;
    }

    /**
     * Overwrite the search index when calling the method via static methods
     */
    public static function setSearchIndex(int $companyId): void
    {
        self::$overWriteSearchIndex = self::searchableIndex() . $companyId;
    }

    public function searchableDeleteRecord(): bool
    {
        return isset($this->id) && isset($this->is_deleted) && ! isset($this->companies_id);
    }

    public function appSearchableIndex()
    {
        $appId = $this->apps_id ?? app(Apps::class)->getId();
        $indexName = self::$overWriteSearchIndex !== null
            ? self::$overWriteSearchIndex
            : self::searchableIndex();

        $indexName = config('scout.prefix') . 'app_' . $appId . '_' . $indexName;
        $searchableArray = $this->toSearchableArray();

        // Manually index the model in the second index
        app('meilisearch')->index($indexName)->addDocuments([$searchableArray]);
    }

    protected static function booted()
    {
        static::saved(function ($model) {
            $model->appSearchableIndex();
        });
    }
}
