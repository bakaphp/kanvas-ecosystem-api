<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Services;

use Closure;
use Kanvas\Scribe\Ledger\Services\AbstractDocumentStateMachineService;
use Kanvas\Scribe\SalesReceipts\Enums\SalesReceiptStatusEnum;
use Kanvas\Scribe\SalesReceipts\Exceptions\InvalidSalesReceiptTransitionException;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Override;

/**
 * Trivial state machine — only RECORDED → VOIDED is allowed. Kept as its own class (vs an inline
 * match) for parity with the other sub-ledger machines + to give the future observer a single
 * attachment point.
 */
class SalesReceiptStateMachineService extends AbstractDocumentStateMachineService
{
    /**
     * @var array<string, list<SalesReceiptStatusEnum>>
     */
    private const ALLOWED = [
        'recorded' => [SalesReceiptStatusEnum::VOIDED],
        'voided' => [],
    ];

    /**
     * @throws InvalidSalesReceiptTransitionException
     */
    public function assertTransition(SalesReceipt $receipt, SalesReceiptStatusEnum $target): void
    {
        $this->guardTransition(
            entityId: (int) $receipt->id,
            current: $receipt->status,
            target: $target,
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
        return 'Sales receipt';
    }

    #[Override]
    protected function transitionExceptionFactory(): Closure
    {
        return fn (string $message) => new InvalidSalesReceiptTransitionException($message);
    }
}
