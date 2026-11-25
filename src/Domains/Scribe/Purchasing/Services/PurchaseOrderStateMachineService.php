<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Purchasing\Services;

use Closure;
use Kanvas\Scribe\Ledger\Services\AbstractDocumentStateMachineService;
use Kanvas\Scribe\Purchasing\Enums\PurchaseOrderStatusEnum;
use Kanvas\Scribe\Purchasing\Exceptions\InvalidPurchaseOrderTransitionException;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Override;

/**
 * Gates every document_status transition on a purchase order the company issues.
 *
 *   DRAFT              → PENDING_APPROVAL | APPROVED | CANCELLED
 *   PENDING_APPROVAL   → APPROVED | DRAFT (rejected) | CANCELLED
 *   APPROVED           → SENT | CANCELLED
 *   SENT               → PARTIALLY_RECEIVED | RECEIVED | CANCELLED
 *   PARTIALLY_RECEIVED → RECEIVED (goods already arrived, so it cannot be cancelled)
 *   RECEIVED, CANCELLED are terminal
 */
class PurchaseOrderStateMachineService extends AbstractDocumentStateMachineService
{
    /**
     * @var array<string, list<PurchaseOrderStatusEnum>>
     */
    private const ALLOWED = [
        'draft' => [
            PurchaseOrderStatusEnum::PENDING_APPROVAL,
            PurchaseOrderStatusEnum::APPROVED,
            PurchaseOrderStatusEnum::CANCELLED,
        ],
        'pending_approval' => [
            PurchaseOrderStatusEnum::APPROVED,
            PurchaseOrderStatusEnum::DRAFT,
            PurchaseOrderStatusEnum::CANCELLED,
        ],
        'approved' => [
            PurchaseOrderStatusEnum::SENT,
            PurchaseOrderStatusEnum::CANCELLED,
        ],
        'sent' => [
            PurchaseOrderStatusEnum::PARTIALLY_RECEIVED,
            PurchaseOrderStatusEnum::RECEIVED,
            PurchaseOrderStatusEnum::CANCELLED,
        ],
        'partially_received' => [
            PurchaseOrderStatusEnum::RECEIVED,
        ],
        'received' => [],
        'cancelled' => [],
    ];

    /**
     * @throws InvalidPurchaseOrderTransitionException
     */
    public function assertTransition(PurchaseOrder $order, PurchaseOrderStatusEnum $target): void
    {
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
        return 'Purchase order';
    }

    #[Override]
    protected function transitionExceptionFactory(): Closure
    {
        return fn (string $message) => new InvalidPurchaseOrderTransitionException($message);
    }
}
