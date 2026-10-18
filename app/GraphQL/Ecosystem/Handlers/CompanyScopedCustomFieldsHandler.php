<?php

declare(strict_types=1);

namespace App\GraphQL\Ecosystem\Handlers;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;
use Override;

/**
 * `hasCustomFields` runs `whereHas('customFields')` on an unhydrated model, so the relation's own
 * `companies_id` guard (which reads `$this->companies_id`) never fires. Without that predicate the
 * subquery cannot use `idx_company_model_name_value_is_deleted` and MySQL probes `apps_custom_fields`
 * once per row of the parent table: 103 s on a 144K-lead tenant for a miss. The subquery gets the
 * same `fromCompany` scope its parent query runs, which turns it into one index seek.
 */
class CompanyScopedCustomFieldsHandler extends WhereConditionsHandler
{
    #[Override]
    public function handleHasCondition(
        Model $model,
        string $relation,
        string $operator,
        int $amount,
        ?array $condition = null,
    ): QueryBuilder {
        return $model
            ->newQuery()
            ->whereHas(
                $relation,
                function (EloquentBuilder $builder) use ($condition): void {
                    $builder->fromCompany();

                    if ($condition !== null && $condition !== []) {
                        $this->__invoke(
                            $builder,
                            $this->prefixConditionWithTableName($condition, $builder->getModel()),
                            $builder->getModel(),
                        );
                    }
                },
                $operator,
                $amount,
            )
            ->getQuery();
    }
}
