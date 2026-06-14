<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\DataTransferObject\AmendInvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * The only legal post-issue mutator on an Invoice.
 *
 * Per plan §7.6 + Scribe/CLAUDE.md — billable snapshot and amounts are frozen at Issue. AmendInvoiceAction
 * exists for the small set of fields that CAN change after issue without breaking the books:
 *   - due_date / expected_payment_date / net_terms_days (customer asked for an extension)
 *   - notes / internal_notes / terms (free-text additions)
 *   - regional_compliance (regulatory fix — e.g. NCF correction)
 *   - external_id / external_url / metadata (cross-system reference fixes)
 *
 * Amounts, currency, fx_rate, billable, document_type, document_status are EXPLICITLY not allowed.
 * For amount adjustments use IssueCreditNoteAction. For wholesale changes use VoidInvoiceAction + recreate.
 *
 * Side effects:
 *   1. Validate the parent invoice is in ISSUED / SENT (not draft, not paid, not voided)
 *   2. Capture before-values for each changed field
 *   3. Apply the new values
 *   4. Append a diff record to metadata.amendments[] (timestamp + user + reason + before/after)
 *
 * @see plan §7.6 — Billable + Vendor snapshots are immutable post-issue
 */
class AmendInvoiceAction
{
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

            $this->applyDateChange($invoice, 'due_date', $this->data->due_date, $changes);
            $this->applyDateChange($invoice, 'expected_payment_date', $this->data->expected_payment_date, $changes);
            $this->applyScalarChange($invoice, 'net_terms_days', $this->data->net_terms_days, $changes);
            $this->applyScalarChange($invoice, 'notes', $this->data->notes, $changes);
            $this->applyScalarChange($invoice, 'internal_notes', $this->data->internal_notes, $changes);
            $this->applyScalarChange($invoice, 'terms', $this->data->terms, $changes);
            $this->applyScalarChange($invoice, 'external_id', $this->data->external_id, $changes);
            $this->applyScalarChange($invoice, 'external_url', $this->data->external_url, $changes);
            $this->applyArrayChange($invoice, 'regional_compliance', $this->data->regional_compliance, $changes);
            $this->mergeMetadata($invoice, $this->data->metadata, $changes);

            if ($changes === []) {
                return $invoice->refresh();
            }

            $this->appendAmendmentHistory($invoice, $changes);

            $invoice->save();

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
    private function applyDateChange(Invoice $invoice, string $field, ?Carbon $newValue, array &$changes): void
    {
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
    private function applyScalarChange(Invoice $invoice, string $field, mixed $newValue, array &$changes): void
    {
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
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function applyArrayChange(Invoice $invoice, string $field, ?array $newValue, array &$changes): void
    {
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
     *
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function mergeMetadata(Invoice $invoice, ?array $newMetadata, array &$changes): void
    {
        if ($newMetadata === null || $newMetadata === []) {
            return;
        }

        $existing = $invoice->metadata ?? [];
        $merged = array_replace_recursive($existing, $newMetadata);

        if ($merged === $existing) {
            return;
        }

        $changes['metadata'] = ['from' => $existing, 'to' => $merged];
        $invoice->metadata = $merged;
    }

    /**
     * @param array<string, array{from: mixed, to: mixed}> $changes
     */
    private function appendAmendmentHistory(Invoice $invoice, array $changes): void
    {
        $metadata = $invoice->metadata ?? [];
        $amendments = $metadata['amendments'] ?? [];

        $amendments[] = [
            'amended_at' => Carbon::now()->toIso8601String(),
            'amended_by_users_id' => $this->user?->getId(),
            'reason' => $this->data->reason,
            'changes' => $changes,
        ];

        $metadata['amendments'] = $amendments;
        $invoice->metadata = $metadata;
    }
}
