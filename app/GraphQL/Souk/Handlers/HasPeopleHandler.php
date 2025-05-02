<?php
declare(strict_types=1);
namespace App\GraphQL\Souk\Handlers;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Guild\Customers\Models\People;

final class HasPeopleHandler extends HasAddressHandler
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
        $peopleQuery = People::query();
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($peopleQuery, $whereConditions, $boolean);
        }
        // @to do: add nested where conditions
        $peopleQuery->from((new People())->getFullTableName());

        if (array_key_exists('AND', $whereConditions)) {
            $this->nestedConditions($peopleQuery, $whereConditions['AND'][0], 'and');
        } elseif (array_key_exists('OR', $whereConditions)) {
            $this->nestedConditions($peopleQuery, $whereConditions['OR'][0], 'or');
        }
        $peopleQuery->whereColumn('id', 'orders.shipping_address_id');
        $builder->whereExists($peopleQuery);
    }
}