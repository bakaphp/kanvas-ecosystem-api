<?php

declare(strict_types=1);

namespace App\GraphQL\Directives;

use App\GraphQL\Execution\BatchLoader\RelationBatchLoader;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nuwave\Lighthouse\Execution\BatchLoader\BatchLoaderRegistry;
use Nuwave\Lighthouse\Execution\ModelsLoader\PaginatedModelsLoader;
use Nuwave\Lighthouse\Execution\ModelsLoader\SimpleModelsLoader;
use Nuwave\Lighthouse\Execution\ResolveInfo;
use Nuwave\Lighthouse\Schema\Directives\BelongsToDirective as LighthouseBelongsToDirective;
use Nuwave\Lighthouse\Schema\Values\FieldValue;
use Nuwave\Lighthouse\Support\Contracts\GraphQLContext;
use Override;

/**
 * Overrides Lighthouse's built-in @belongsTo so batched relations use our wave-tolerant
 * RelationBatchLoader (see that class for the Sentry KANVAS-ECOSYSTEM-631 root cause).
 *
 * The body mirrors Nuwave\Lighthouse\Schema\Directives\RelationDirective::resolveField verbatim
 * except for the batch loader instance it hands to BatchLoaderRegistry. Keep it in sync when
 * upgrading Lighthouse — the only intended deviation is the RelationBatchLoader class.
 */
class BelongsToDirective extends LighthouseBelongsToDirective
{
    #[Override]
    public function resolveField(FieldValue $fieldValue): callable
    {
        $relationName = $this->relation();

        return function (Model $parent, array $args, GraphQLContext $context, ResolveInfo $resolveInfo) use ($relationName) {
            $decorateBuilder = $this->makeBuilderDecorator($parent, $args, $context, $resolveInfo);
            $paginationArgs = $this->paginationArgs($args, $resolveInfo);

            $relation = $parent->{$relationName}();
            assert($relation instanceof Relation);

            // We can shortcut the resolution if the client only queries for a foreign key
            // that we know to be present on the parent model.
            if (
                $this->lighthouseConfig['shortcut_foreign_key_selection']
                && (array_diff(array_keys($resolveInfo->getFieldSelection()), ['id', '__typename']) === [])
                && $relation instanceof BelongsTo
                && $args === []
            ) {
                $id = $parent->getAttribute($relation->getForeignKeyName());

                if ($id === null) {
                    return null;
                }

                // If the relation is polymorphic, instantiate and hydrate the model instance.
                // This allows TypeRegistry::typeResolverFallback to resolve the correct type.
                if ($relation instanceof MorphTo) {
                    $model = $relation->getModel();
                    $model->id = $id;

                    return $model;
                }

                return ['id' => $id];
            }

            if (
                $this->lighthouseConfig['batchload_relations']
                // Batch loading joins across both models, thus only works if they are on the same connection
                && $this->isSameConnection($relation)
            ) {
                $relationBatchLoader = BatchLoaderRegistry::instance(
                    $this->qualifyPath($args, $resolveInfo),
                    static fn (): RelationBatchLoader => new RelationBatchLoader(
                        $paginationArgs === null
                            ? new SimpleModelsLoader($relationName, $decorateBuilder)
                            : new PaginatedModelsLoader($relationName, $decorateBuilder, $paginationArgs),
                    ),
                );

                return $relationBatchLoader->load($parent);
            }

            $decorateBuilder($relation);

            return $paginationArgs !== null
                ? $paginationArgs->applyToBuilder($relation)
                : $relation->getResults();
        };
    }
}
