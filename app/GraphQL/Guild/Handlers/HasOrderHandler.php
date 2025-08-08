<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Handlers;

use App\GraphQL\Souk\Handlers\HasAddressHandler;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Souk\Orders\Models\Order;
use Override;

final class HasOrderHandler extends HasAddressHandler
{
    /**
     * @param  array<string, mixed>  $whereConditions
     */
    #[Override]
    public function __invoke(
        object $builder,
        array $whereConditions,
        ?Model $model = null,
        string $boolean = 'and',
    ): void {
        $orderQuery = Order::query();
        if ($column = $whereConditions['column'] ?? null) {
            $this->assertValidColumnReference($column);
            $this->operator->applyConditions($orderQuery, $whereConditions, $boolean);
        }
        // @to do: add nested where conditions
        $orderQuery->from((new Order())->getFullTableName());

        if (array_key_exists('AND', $whereConditions)) {
            $this->nestedConditions($orderQuery, $whereConditions['AND'][0], 'and');
        } elseif (array_key_exists('OR', $whereConditions)) {
            $this->nestedConditions($orderQuery, $whereConditions['OR'][0], 'or');
        }
        $orderQuery->whereColumn('id', 'orders.shipping_address_id');
        $builder->whereExists($orderQuery);
    }
}
