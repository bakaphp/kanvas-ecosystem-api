<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\DifferenceSourceEnum;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;

/**
 * Records how one difference found in a check of the order was clarified with the customer.
 */
class ResolveSalesOrderDifferenceAction
{
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly string $key,
        protected readonly string $resolution,
        protected readonly DifferenceSourceEnum $source = DifferenceSourceEnum::REVIEW,
    ) {
    }

    public function execute(): SalesOrder
    {
        $resolution = Str::trimToNull($this->resolution)
            ?? throw new ValidationException('Write down how the difference was clarified.');

        $column = $this->source->column();
        $check = $this->order->{$column};
        $found = false;

        foreach ($check['differences'] ?? [] as $index => $difference) {
            if ($difference['key'] === $this->key) {
                $check['differences'][$index]['resolution'] = $resolution;
                $found = true;
            }
        }

        if (! $found) {
            throw new ValidationException("Sales order {$this->order->order_number} has no difference \"{$this->key}\".");
        }

        $this->order->{$column} = $check;
        $this->order->saveOrFail();

        return $this->order;
    }
}
