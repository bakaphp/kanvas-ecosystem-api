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

        if (array_key_exists('AND', $whereConditions)) {
            $this->nestedConditions($addressBuilder, $whereConditions['AND'][0], 'and');
        } elseif (array_key_exists('OR', $whereConditions)) {
            $this->nestedConditions($addressBuilder, $whereConditions['OR'][0], 'or');
        }

        $builder->whereExists($addressBuilder);
    }

    public function nestedConditions(object $builder, array $whereConditions, string $boolean = 'and'): bool
    {
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($builder, $whereConditions, $boolean);
        }
        if (array_key_exists('AND', $whereConditions)) {
            $this->nestedConditions($builder, $whereConditions['AND'][0], 'and');
        } elseif (array_key_exists('OR', $whereConditions)) {
            $this->nestedConditions($builder, $whereConditions['OR'][0], 'or');
        }

        return true;
    }
}
