<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Gates every document_status transition on Invoice.
 *
 * Allowed graph:
 *   DRAFT  → ISSUED
 *   ISSUED → SENT | PAID | VOIDED
 *   SENT   → PAID | VOIDED
 *   PAID   → (terminal — no transitions out)
 *   VOIDED → (terminal — no transitions out)
 *
 * Voiding a PAID invoice is intentionally not allowed — the right answer there is to issue a credit_note
 * via IssueCreditNoteAction (which posts a contra JE) instead.
 *
 * Native CRUD Actions call assertTransition() before flipping status. Direct status mutation outside this
 * machine is banned (will be enforced by InvoiceObserver in a follow-up; for now it's a code-review rule).
 *
 * @see plan §7.1 — two-axis status with state machine
 */
class InvoiceStateMachine
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
        'paid' => [],     // terminal
        'voided' => [],   // terminal
    ];

    /**
     * @throws InvalidInvoiceTransitionException
     */
    public function assertTransition(Invoice $invoice, InvoiceDocumentStatusEnum $target): void
    {
        $current = $invoice->document_status;

        if (! $this->canTransition($current, $target)) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$invoice->id} cannot transition document_status "
                . "from '{$current->value}' to '{$target->value}'. "
                . 'Allowed targets: ' . $this->formatAllowed($current) . '.'
            );
        }
    }

    public function canTransition(InvoiceDocumentStatusEnum $from, InvoiceDocumentStatusEnum $to): bool
    {
        if ($from === $to) {
            return true;       // no-op transitions are allowed (idempotent calls)
        }

        $allowed = self::ALLOWED[$from->value] ?? [];

        return in_array($to, $allowed, true);
    }

    private function formatAllowed(InvoiceDocumentStatusEnum $from): string
    {
        $allowed = self::ALLOWED[$from->value] ?? [];
        if ($allowed === []) {
            return '(none — terminal state)';
        }

        return implode(', ', array_map(fn (InvoiceDocumentStatusEnum $e) => $e->value, $allowed));
    }
}
