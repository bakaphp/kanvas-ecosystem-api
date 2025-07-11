<?php

declare(strict_types=1);

namespace App\Macros;

use Baka\Search\SearchEngineResolver;
use Laravel\Scout\Builder;
use Typesense\Client;
use Kanvas\Apps\Models\Apps;
class ScoutMacros
{
    public static function register(): void
    {
        Builder::macro('semantic', function (array $options = []) {
            /** @var Builder $builder */
            $builder = $this;
            $app = app(Apps::class);
            $searchSettings = $app->get('typesense_search_settings');
            $client = SearchEngineResolver::getTypesenseClient($searchSettings);
            $model = $builder->model;
            $collection = $model->searchableAs();
            $query = $builder->query;
            $fields = method_exists($model, 'typesenseQueryFields')
                ? implode(',', $model->typesenseQueryFields())
                : 'embedding';

            return $client->collections[$collection]->documents->search(array_merge([
                'q' => $query,
                'query_by' => $fields,
                'per_page' => $builder->limit ?? 10,
                'prefix' => false,
                'exclude_fields' => 'embedding',
            ], $options));
        });
    }
}
