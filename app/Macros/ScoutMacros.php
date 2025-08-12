<?php

declare(strict_types=1);

namespace App\Macros;

use Baka\Search\SearchEngineResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Laravel\Scout\Builder;

class ScoutMacros
{
    public static function register(): void
    {
        Builder::macro('semantic', function (array $options = []) {
            /** @var Builder $builder */
            $builder = $this;
            $app = app(Apps::class);
            $query = trim($builder->query);
            $model = $builder->model;
            if (! $builder->query || trim($builder->query) === '') {
                return collect([]);
            }

            $perPage = $builder->limit ?? 10;

            if ($app->get(AppEnums::CACHE_SEARCH->getValue())) {
                $optionsHash = md5(serialize($options));
                $key = "semantic_search:{$app->getId()}:{$query}:{$perPage}:{$optionsHash}";
                $seconds = (int)$app->get(AppEnums::CACHE_SEARCH_TTL->getValue(), 60);

                return Cache::remember($key, $seconds, function () use ($app, $model, $options, $query, $key, $perPage) {
                    // Fire workflow event
                    $app->fireWorkflow(
                        event: WorkflowEnum::SEARCH->value,
                        params: [
                            'search_type' => 'product',
                            'search' => $query,
                            'cache_key' => $key,
                        ]
                    );
                    return ScoutMacros::getTypesenseData($app, $model, $query, $perPage, $options);
                });
            }
            $app->fireWorkflow(
                event: WorkflowEnum::SEARCH->value,
                params: [
                    'search_type' => 'product',
                    'search' => $query,
                    'cache_key' => null,
                ]
            );

            return ScoutMacros::getTypesenseData($app, $model, $query, $perPage, $options);
        });
    }

    public static function getTypesenseData(Apps $app, Model $model, string $query, $perPage = 10, array $options = [])
    {
        $searchSettings = $app->get('typesense_search_settings');
        $client = SearchEngineResolver::getTypesenseClient($searchSettings);
        $collection = $model->searchableAs();
        $fields = method_exists($model, 'typesenseQueryFields')
            ? implode(',', $model->typesenseQueryFields())
            : 'embedding';

        $searchParams = array_merge([
            'q' => $query,
            'query_by' => $fields,
            'per_page' => $perPage,
            'prefix' => false,
            'exclude_fields' => 'embedding',
        ], $options);

        return $client->collections[$collection]->documents->search($searchParams);
    }
}
