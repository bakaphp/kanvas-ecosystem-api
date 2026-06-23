<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Closure;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Exceptions\InvalidBillTransitionException;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Services\AbstractDocumentStateMachineService;
use Override;

/**
 * Gates Bill document_status transitions.
 *
 *   DRAFT     → RECEIVED   (posts AP JE)
 *   RECEIVED  → PAID       (recompute from allocations — balance hit zero)
 *   RECEIVED  → VOIDED     (posts reversal JE)
 *   PAID      → (terminal — issue a vendor credit instead of voiding)
 *   VOIDED    → (terminal)
 */
class BillStateMachineService extends AbstractDocumentStateMachineService
{
    /**
     * @var array<string, list<BillDocumentStatusEnum>>
     */
    private const ALLOWED = [
        'draft' => [
            BillDocumentStatusEnum::RECEIVED,
        ],
        'received' => [
            BillDocumentStatusEnum::PAID,
            BillDocumentStatusEnum::VOIDED,
        ],
        'paid' => [],
        'voided' => [],
    ];

    /**
     * @throws InvalidBillTransitionException
     */
    public function assertTransition(Bill $bill, BillDocumentStatusEnum $target): void
    {
        $this->guardTransition(
            entityId: (int) $bill->id,
            current: $bill->document_status,
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
        return 'Bill';
    }

    #[Override]
    protected function transitionExceptionFactory(): Closure
    {
        return fn (string $message) => new InvalidBillTransitionException($message);
    }
}
