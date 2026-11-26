<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Exceptions\InvalidSalesOrderTransitionException;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Kanvas\Scribe\SalesOrders\Services\SalesOrderReviewService;

/**
 * Reviews a received sales order against its quote (and the agreed terms, when given) and records every
 * difference. A difference stays open until Commercial writes down how it was clarified.
 */
class ReviewSalesOrderAction
{
    /**
     * @param array{incoterm?: string|null, payment_terms_days?: int|null} $agreement
     */
    public function __construct(
        protected readonly SalesOrder $order,
        protected readonly array $agreement = [],
        protected readonly SalesOrderReviewService $review = new SalesOrderReviewService(),
    ) {
    }

    public function execute(): SalesOrder
    {
        if ($this->order->document_status !== SalesOrderStatusEnum::RECEIVED) {
            throw new InvalidSalesOrderTransitionException(
                "Sales order {$this->order->order_number} is {$this->order->document_status->value}: only a received order can be reviewed."
            );
        }

        if ($this->order->quote === null) {
            throw new ValidationException("Sales order {$this->order->order_number} has no quote to be reviewed against.");
        }

        $this->order->review = [
            'agreement' => $this->agreement,
            'differences' => $this->review->differences($this->order, $this->order->quote, $this->agreement),
        ];
        $this->order->reviewed_at = Carbon::now();
        $this->order->saveOrFail();

        return $this->order;
    }
}
