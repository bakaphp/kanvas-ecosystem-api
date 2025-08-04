<?php

declare(strict_types=1);

namespace App\Macros;

use Baka\Search\SearchEngineResolver;
use Kanvas\Apps\Models\Apps;
use Kanvas\Workflow\Enums\WorkflowEnum;
use Laravel\Scout\Builder;
use Kanvas\Enums\AppEnums;
use Illuminate\Support\Facades\Cache;

class ScoutMacros
{

    public static function register(): void
    {
        Builder::macro('semantic', function (array $options = []) {
            /** @var Builder $builder */
            $builder = $this;
            $app = app(Apps::class);
            
            if (!$builder->query || trim($builder->query) === '') {
                return collect([]);
            }
            
            $searchSettings = $app->get('typesense_search_settings');
            $client = SearchEngineResolver::getTypesenseClient($searchSettings);
            $model = $builder->model;
            $collection = $model->searchableAs();
            $query = trim($builder->query);
            $fields = method_exists($model, 'typesenseQueryFields')
                ? implode(',', $model->typesenseQueryFields())
                : 'embedding';
    
            $perPage = $builder->limit ?? 10;
            $searchParams = array_merge([
                'q' => $query,
                'query_by' => $fields,
                'per_page' => $perPage,
                'prefix' => false,
                'exclude_fields' => 'embedding',
            ], $options);
            if ($app->get(AppEnums::CACHE_SEARCH->getValue())) {

                $optionsHash = md5(serialize($options));
                $key = "semantic_search:{$app->getId()}:{$query}:{$perPage}:{$optionsHash}";
                $seconds = (int)$app->get(AppEnums::CACHE_SEARCH_TTL->getValue(), 60);
    
                return Cache::remember($key, $seconds, function () use ($app, $client, $collection, $searchParams, $query, $key) {
                    // Fire workflow event
                    $app->fireWorkflow(
                        event: WorkflowEnum::SEARCH->value,
                        params: [
                            'search_type' => 'product',
                            'search' => $query,
                            'cache_key' => $key,
                        ]
                    );
    
                    return $client->collections[$collection]->documents->search($searchParams);
                });
            }
    
            $app->fireWorkflow(
                event: WorkflowEnum::SEARCH->value,
                params: [
                    'search_type' => 'product',
                    'search' => $query,
                ]
            );
    
            return $client->collections[$collection]->documents->search($searchParams);
        });
    }
}
