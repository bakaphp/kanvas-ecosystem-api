<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoice as AmendInvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * Changes the permitted post-issue fields without changing amounts, currency or the customer snapshot.
 * Amount adjustments require a credit note; wholesale changes require voiding and recreating the invoice.
 *
 * Each amendment emits a `scribe.invoice.amended` ledger event with its diff, read by
 * Invoice::amendmentHistory(). The ledger uses a separate connection from the accounting transaction.
 *
 * @see docs/accounting/cfo-agent-plan.md §7.4 — Billable snapshots are frozen at issue
 */
class AmendInvoiceAction
{
    public const string LEDGER_EVENT = 'scribe.invoice.amended';

    public function __construct(
        public readonly Invoice $invoice,
        public readonly AmendInvoiceData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        $this->validateAmendable();

        return DB::connection('accounting')->transaction(function (): Invoice {
            $invoice = $this->invoice;
            $changes = [];

            $this->applyDateChange(
                $invoice,
                'due_date',
                $this->data->due_date,
                $changes
            );
            $this->applyDateChange(
                $invoice,
                'expected_payment_date',
                $this->data->expected_payment_date,
                $changes
            );
            $this->applyValueChange(
                $invoice,
                'net_terms_days',
                $this->data->net_terms_days,
                $changes
            );
            $this->applyValueChange(
                $invoice,
                'notes',
                $this->data->notes,
                $changes
            );
            $this->applyValueChange(
                $invoice,
                'internal_notes',
                $this->data->internal_notes,
                $changes
            );
            $this->applyValueChange(
                $invoice,
                'terms',
                $this->data->terms,
                $changes
            );
            $this->applyValueChange(
                $invoice,
                'external_id',
                $this->data->external_id,
                $changes
            );
            $this->applyValueChange(
                $invoice,
                'external_url',
                $this->data->external_url,
                $changes
            );
            $this->applyValueChange(
                $invoice,
                'regional_compliance',
                $this->data->regional_compliance,
                $changes
            );
            $this->mergeMetadata($invoice, $this->data->metadata, $changes);

            if ($changes === []) {
                return $invoice->refresh();
            }

            $invoice->save();

            $invoice->emitLedgerEvent(
                eventType: self::LEDGER_EVENT,
                payload: [
                    'invoice_number' => $invoice->invoice_number,
                    'reason' => $this->data->reason,
                    'amended_by_users_id' => $this->user?->getId(),
                    'changes' => $changes,
                ],
                actorType: $this->user !== null ? 'User' : null,
                actorId: $this->user?->getId(),
            );

            return $invoice->refresh();
        });
    }

    private function validateAmendable(): void
    {
        $allowed = [
            InvoiceDocumentStatusEnum::ISSUED,
            InvoiceDocumentStatusEnum::SENT,
        ];

        if (! in_array($this->invoice->document_status, $allowed, true)) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$this->invoice->id} cannot be amended — status is "
                . "'{$this->invoice->document_status->value}'. Amendments are only valid for issued / sent "
                . 'invoices. Use UpdateInvoiceAction for drafts, IssueCreditNoteAction for amount adjustments, '
                . 'or VoidInvoiceAction for wholesale changes.'
            );
        }
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function applyDateChange(
        Invoice $invoice,
        string $field,
        ?Carbon $newValue,
        array &$changes
    ): void {
        if ($newValue === null) {
            return;
        }

        $old = $invoice->{$field};
        $oldFormatted = $old instanceof Carbon ? $old->format('Y-m-d') : $old;
        $newFormatted = $newValue->format('Y-m-d');

        if ($oldFormatted === $newFormatted) {
            return;
        }

        $changes[$field] = ['from' => $oldFormatted, 'to' => $newFormatted];
        $invoice->{$field} = $newValue;
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function applyValueChange(
        Invoice $invoice,
        string $field,
        mixed $newValue,
        array &$changes
    ): void {
        if ($newValue === null) {
            return;
        }

        $old = $invoice->{$field};
        if ($old === $newValue) {
            return;
        }

        $changes[$field] = ['from' => $old, 'to' => $newValue];
        $invoice->{$field} = $newValue;
    }

    /**
     * Metadata merges rather than replaces — the caller can add keys without overwriting unrelated ones.
     * The diff names only the top-level keys this call changed, and `amendments` is never taken from the
     * caller: the history is the ledger, not something a metadata payload can write.
     *
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function mergeMetadata(Invoice $invoice, ?array $newMetadata, array &$changes): void
    {
        unset($newMetadata['amendments']);

        if ($newMetadata === null || $newMetadata === []) {
            return;
        }

        $existing = $invoice->metadata ?? [];
        $merged = array_replace_recursive($existing, $newMetadata);

        $diff = [];
        foreach (array_keys($newMetadata) as $key) {
            if (($existing[$key] ?? null) !== $merged[$key]) {
                $diff[$key] = ['from' => $existing[$key] ?? null, 'to' => $merged[$key]];
            }
        }

        if ($diff === []) {
            return;
        }

        $changes['metadata'] = $diff;
        $invoice->metadata = $merged;
    }
}
