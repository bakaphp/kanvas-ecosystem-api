<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Customers\Models\Address;
use Nuwave\Lighthouse\WhereConditions\WhereConditionsHandler;

final class HasAddressHandler extends WhereConditionsHandler
{
    /**
     * @param  array<string, mixed>  $whereConditions
     */
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $addressBuilder = Address::query();

        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($addressBuilder, $whereConditions, $boolean);
        }
        // @to do: add nested where conditions
        $addressBuilder->from((new Address())->getFullTableName());
        $builder->whereExists($addressBuilder);
    }
}
