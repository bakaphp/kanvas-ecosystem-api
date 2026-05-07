<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

class OrderTransitionHistoryVariantHandler extends WhereConditionsHandler
{
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);

            $builder->whereHas('order.allItems', function ($query) use ($whereConditions, $boolean) {
                $this->operator->applyConditions($query, $whereConditions, $boolean);
            });
        }
    }
}
