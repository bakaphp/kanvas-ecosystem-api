<?php

declare(strict_types=1);

namespace Baka\Search;

use Algolia\AlgoliaSearch\Api\SearchClient;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Throwable;

class AlgoliaSettingsReconciler
{
    public function __construct(
        private readonly SearchClient $client,
        private readonly array $settings = [],
    ) {
    }

    public static function forApp(Apps $app): self
    {
        $settings = $app->get('algolia_search_settings') ?? [];

        return new self(SearchEngineResolver::getAlgoliaClient($settings), $settings);
    }

    public function target(Model $model): string
    {
        return (string) json_encode([
            $model->searchableAs(),
            SearchEngineResolver::algoliaCredentialsFromSettings($this->settings)['app_id'],
        ]);
    }

    public function missing(Model $model, bool $force = false): array
    {
        $declared = $this->declaredSettings($model);

        if ($declared === [] || $force) {
            return $declared;
        }

        $live = $this->liveSettings($model->searchableAs());

        return array_filter(
            $declared,
            fn (string $key) => empty($live[$key] ?? null),
            ARRAY_FILTER_USE_KEY
        );
    }

    public function reconcile(Model $model, bool $force = false): array
    {
        $missing = $this->missing($model, $force);

        if ($missing === []) {
            return ['applied' => [], 'error' => null];
        }

        try {
            $this->client->setSettings($model->searchableAs(), $missing, true);
        } catch (Throwable $e) {
            return ['applied' => [], 'error' => $e->getMessage()];
        }

        return ['applied' => $missing, 'error' => null];
    }

    private function declaredSettings(Model $model): array
    {
        return method_exists($model, 'algoliaIndexSettings') ? $model->algoliaIndexSettings() : [];
    }

    private function liveSettings(string $index): array
    {
        try {
            return $this->client->getSettings($index);
        } catch (Throwable) {
            return [];
        }
    }
}
