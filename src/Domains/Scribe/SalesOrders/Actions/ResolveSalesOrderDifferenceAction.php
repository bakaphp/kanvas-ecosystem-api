<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Baka\Support\Str;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;

/**
 * Records how one difference found in the review was clarified with the customer.
 */
class ResolveSalesOrderDifferenceAction
{
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly string $key,
        protected readonly string $resolution,
    ) {
    }

    public function execute(): SalesOrder
    {
        $resolution = Str::trimToNull($this->resolution)
            ?? throw new ValidationException('Write down how the difference was clarified.');

        $review = $this->order->review;
        $found = false;

        foreach ($review['differences'] ?? [] as $index => $difference) {
            if ($difference['key'] === $this->key) {
                $review['differences'][$index]['resolution'] = $resolution;
                $found = true;
            }
        }

        if (! $found) {
            throw new ValidationException("Sales order {$this->order->order_number} has no difference \"{$this->key}\".");
        }

        $this->order->review = $review;
        $this->order->saveOrFail();

        return $this->order;
    }
}
