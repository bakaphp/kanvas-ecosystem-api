<?php

declare(strict_types=1);

namespace Baka\Search;

use Baka\Search\Contracts\NameSearchInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Laravel\Scout\Builder as ScoutBuilder;
use Laravel\Scout\Engines\Engine;
use Override;

/**
 * One Scout query per name through whatever engine the app resolved to. Scout translates the tenant
 * where()s into each engine's own filter syntax, so this covers Algolia, Meilisearch and Typesense
 * without engine-specific code — at one round trip per name. Engines with a batch API get their own
 * implementation; this is the floor that keeps every other engine off the database.
 */
class EngineNameSearch implements NameSearchInterface
{
    public function __construct(
        private readonly Engine $engine,
    ) {
    }

    #[Override]
    public function idsFor(
        Model $model,
        Apps $app,
        Companies $company,
        string $queryBy,
        array $terms,
        int $perTerm,
    ): array {
        // Priming `app` keeps isTypesense() on its cheap path: without it, resolvedEngineName()
        // falls through to withTrashed(), which these is_deleted-flag models don't have, so every
        // call throws and catches a BadMethodCallException before re-reading the app settings.
        $model->setRelation('app', $app);
        $queryByIsRequired = method_exists($model, 'isTypesense') && $model->isTypesense();

        $ids = [];

        foreach ($terms as $term) {
            $builder = new ScoutBuilder($model, $term['query']);
            $builder->where('apps_id', $app->getId())
                ->where('companies_id', $company->getId())
                ->take($perTerm);

            if ($queryByIsRequired) {
                $builder->options(['query_by' => $queryBy]);
            }

            array_push($ids, ...$this->engine->keys($builder)->all());
        }

        return array_values(array_unique(array_map('strval', $ids)));
    }
}
