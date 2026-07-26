<?php

declare(strict_types=1);

namespace App\GraphQL\Execution\BatchLoader;

use GraphQL\Deferred;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\Execution\BatchLoader\RelationBatchLoader as LighthouseRelationBatchLoader;
use Nuwave\Lighthouse\Execution\Utils\ModelKey;
use Override;

/**
 * A RelationBatchLoader that tolerates parents registered across several resolution waves.
 *
 * Lighthouse's own loader resolves exactly once (guarded by a `hasResolved` flag) and assumes
 * every parent has been registered via load() before the first deferred fires. That assumption
 * breaks when a batched relation sits below nested paginated fields (e.g. categories ->
 * productsTags -> variantsPaginate -> variant.product) and/or below a field that returns its own
 * Deferred (our @cacheRedis directive): the child batch loader instance is shared across the whole
 * subtree, so parents arrive in more than one wave. The stock loader locks after wave one, and any
 * parent added afterwards is never loaded — reading its key throws
 * `Undefined array key "Kanvas\Inventory\Variants\Models\Variants:242895"` from within the loader
 * (Sentry KANVAS-ECOSYSTEM-631).
 *
 * This override keys resolution off the results map instead of a one-shot flag: whenever a
 * deferred asks for a key that has not been loaded yet, it resolves only the still-pending parents.
 * Single-wave queries behave identically to the stock loader (one batch query); multi-wave queries
 * simply issue one extra batch query per additional wave instead of erroring.
 */
class RelationBatchLoader extends LighthouseRelationBatchLoader
{
    #[Override]
    public function load(Model $model): Deferred
    {
        $modelKey = ModelKey::build($model);
        $this->parents[$modelKey] = $model;

        return new Deferred(function () use ($modelKey) {
            if (! array_key_exists($modelKey, $this->results)) {
                $this->resolve();
            }

            return $this->results[$modelKey] ?? null;
        });
    }

    #[Override]
    protected function resolve(): void
    {
        $pending = array_filter(
            $this->parents,
            fn (string $modelKey): bool => ! array_key_exists($modelKey, $this->results),
            ARRAY_FILTER_USE_KEY,
        );

        if ($pending === []) {
            return;
        }

        $parentModels = new EloquentCollection($pending);

        $parentsGroupedByClass = $parentModels->groupBy(
            static fn (Model $model): string => $model::class,
            true,
        );

        foreach ($parentsGroupedByClass as $parentsOfSameClass) {
            $this->modelsLoader->load($parentsOfSameClass);
        }

        foreach ($parentModels as $model) {
            $this->results[ModelKey::build($model)] = $this->modelsLoader->extract($model);
        }
    }
}
