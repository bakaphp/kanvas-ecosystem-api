<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Closure;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Services\AbstractDocumentStateMachineService;

/**
 * Gates every document_status transition on Invoice.
 *
 * Allowed graph:
 *   DRAFT  → ISSUED
 *   ISSUED → SENT | PAID | VOIDED
 *   SENT   → PAID | VOIDED
 *   PAID   → (terminal)
 *   VOIDED → (terminal)
 *
 * Voiding a PAID invoice is intentionally not allowed — issue a credit_note via
 * IssueCreditNoteAction (which posts a contra JE) instead.
 *
 * @see AbstractDocumentStateMachineService — shared canTransition / guardTransition / formatAllowed
 */
class InvoiceStateMachineService extends AbstractDocumentStateMachineService
{
    /**
     * @var array<string, list<InvoiceDocumentStatusEnum>>
     */
    private const ALLOWED = [
        'draft' => [
            InvoiceDocumentStatusEnum::ISSUED,
        ],
        'issued' => [
            InvoiceDocumentStatusEnum::SENT,
            InvoiceDocumentStatusEnum::PAID,
            InvoiceDocumentStatusEnum::VOIDED,
        ],
        'sent' => [
            InvoiceDocumentStatusEnum::PAID,
            InvoiceDocumentStatusEnum::VOIDED,
        ],
        'paid' => [],
        'voided' => [],
    ];

    /**
     * @throws InvalidInvoiceTransitionException
     */
    public function assertTransition(Invoice $invoice, InvoiceDocumentStatusEnum $target): void
    {
        $this->guardTransition(
            entityId: (int) $invoice->id,
            current: $invoice->document_status,
            target: $target,
            fieldName: 'document_status',
        );
    }

    protected function allowedTransitions(): array
    {
        return self::ALLOWED;
    }

    protected function entityLabel(): string
    {
        return 'Invoice';
    }

    protected function transitionExceptionFactory(): Closure
    {
        return fn (string $message) => new InvalidInvoiceTransitionException($message);
    }
}
