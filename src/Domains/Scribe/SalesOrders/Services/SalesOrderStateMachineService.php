<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Services;

use Closure;
use Kanvas\Scribe\Ledger\Services\AbstractDocumentStateMachineService;
use Kanvas\Scribe\SalesOrders\Enums\SalesOrderStatusEnum;
use Kanvas\Scribe\SalesOrders\Exceptions\InvalidSalesOrderTransitionException;
use Kanvas\Scribe\SalesOrders\Models\SalesOrder;
use Override;

/**
 * Gates every document_status transition on a sales order.
 *
 *   RECEIVED  → CONFIRMED (gate 1) | CANCELLED
 *   CONFIRMED → ACCEPTED (gate 2) | RETURNED | CANCELLED
 *   RETURNED  → RECEIVED (Commercial renegotiated it) | CANCELLED
 *   ACCEPTED  → SHIPPED | CANCELLED
 *   SHIPPED   → DELIVERED
 *   DELIVERED → CLOSED
 *   CLOSED, CANCELLED are terminal
 */
class SalesOrderStateMachineService extends AbstractDocumentStateMachineService
{
    /**
     * @var array<string, list<SalesOrderStatusEnum>>
     */
    private const ALLOWED = [
        'received' => [
            SalesOrderStatusEnum::CONFIRMED,
            SalesOrderStatusEnum::CANCELLED,
        ],
        'confirmed' => [
            SalesOrderStatusEnum::ACCEPTED,
            SalesOrderStatusEnum::RETURNED,
            SalesOrderStatusEnum::CANCELLED,
        ],
        'returned' => [
            SalesOrderStatusEnum::RECEIVED,
            SalesOrderStatusEnum::CANCELLED,
        ],
        'accepted' => [
            SalesOrderStatusEnum::SHIPPED,
            SalesOrderStatusEnum::CANCELLED,
        ],
        'shipped' => [
            SalesOrderStatusEnum::DELIVERED,
        ],
        'delivered' => [
            SalesOrderStatusEnum::CLOSED,
        ],
        'closed' => [],
        'cancelled' => [],
    ];

    /**
     * Every action uses this as its "the order is in the state before this one" check, so a move to the state
     * the order is already in is refused here; the shared guard would let it through as a no-op.
     *
     * @throws InvalidSalesOrderTransitionException
     */
    public function assertTransition(SalesOrder $order, SalesOrderStatusEnum $target): void
    {
        if ($order->document_status === $target) {
            throw new InvalidSalesOrderTransitionException(
                "Sales order {$order->order_number} is already {$target->value}."
            );
        }

        $this->guardTransition(
            entityId: (int) $order->id,
            current: $order->document_status,
            target: $target,
            fieldName: 'document_status',
        );
    }

    #[Override]
    protected function allowedTransitions(): array
    {
        return self::ALLOWED;
    }

    #[Override]
    protected function entityLabel(): string
    {
        return 'Sales order';
    }

    #[Override]
    protected function transitionExceptionFactory(): Closure
    {
        return fn (string $message) => new InvalidSalesOrderTransitionException($message);
    }
}
