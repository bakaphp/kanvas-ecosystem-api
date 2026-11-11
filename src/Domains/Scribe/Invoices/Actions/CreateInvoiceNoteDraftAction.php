<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Contracts\BillableInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfIssuanceService;
use Kanvas\Scribe\Invoices\Actions\Concerns\PersistsInvoiceLines;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * The DRAFT row of a credit or debit note: header, lines and tax lines, the parent's frozen billable
 * snapshot, no number and no journal entry. IssueCreditNoteAction and IssueDebitNoteAction create through
 * here and then issue (or park the draft for approval).
 *
 * Amounts are stored positive; the document type and the JE direction carry the sign. A credit note may
 * not exceed its parent; a debit note owes on top of the parent, so it has a balance due of its own.
 */
class CreateInvoiceNoteDraftAction
{
    use PersistsInvoiceLines;

    public function __construct(
        public readonly DocumentTypeEnum $noteType,
        public readonly ?Invoice $parentInvoice,
        public readonly InvoiceData $data,
        public readonly ?BillableInterface $billable = null,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        if (! $this->noteType->isNote()) {
            throw new InvalidInvoiceTransitionException("{$this->noteType->label()} is not a note type.");
        }

        $parent = $this->parentInvoice;
        $isDebit = $this->noteType === DocumentTypeEnum::DEBIT_NOTE;

        if ($parent !== null) {
            new EcfIssuanceService()->assertNoteNumbersAvailable($parent, $isDebit);
        }
        [$totals, $baseTotals] = $this->data->totals();

        if ($totals['total'] <= 0) {
            throw new InvalidInvoiceTransitionException("{$this->noteType->label()} total must be greater than zero.");
        }

        if (! $isDebit && $parent !== null && $totals['total'] > (float) $parent->total_native + 0.0001) {
            throw new InvalidInvoiceTransitionException(
                "Credit note total ({$totals['total']}) exceeds parent invoice total ({$parent->total_native})."
            );
        }

        $note = new Invoice();
        $note->apps_id = $this->data->app->getId();
        $note->companies_id = $this->data->company->getId();
        $note->document_type = $this->noteType;
        $note->document_status = InvoiceDocumentStatusEnum::DRAFT;
        $note->collection_state = null;
        $note->tax_calculation_mode = $this->data->tax_calculation_mode;
        $note->delivery_status = 'not_applicable';
        $note->net_terms_days = $isDebit ? (int) ($this->data->net_terms_days ?? $parent?->net_terms_days ?? 0) : 0;
        $note->issued_date = $this->data->issued_date;
        $note->due_date = $isDebit ? $this->data->due_date : null;
        $note->currency = $this->data->currency;
        $note->fx_rate_to_base = $this->data->fx_rate_to_base;
        $note->fx_rate_at = $this->data->issued_date ?? Carbon::now();
        $note->subtotal_native = $totals['subtotal'];
        $note->tax_native = $totals['tax'];
        $note->discount_native = $totals['discount'];
        $note->total_native = $totals['total'];
        $note->paid_native = 0.0;
        $note->balance_due_native = $isDebit ? $totals['total'] : 0.0;
        $note->subtotal_base = $baseTotals['subtotal'];
        $note->tax_base = $baseTotals['tax'];
        $note->discount_base = $baseTotals['discount'];
        $note->total_base = $baseTotals['total'];
        $note->paid_base = 0.0;
        $note->balance_due_base = $isDebit ? $baseTotals['total'] : 0.0;
        $note->tax_metadata = $this->data->tax_metadata;
        $note->regional_compliance = $this->data->regional_compliance;
        $note->notes = $this->data->notes;
        $note->internal_notes = $this->data->internal_notes;
        $note->terms = $this->data->terms;
        $note->parent_invoice_id = $parent?->id;
        $note->invoice_number = $this->data->invoice_number;
        $note->source = $this->data->source;
        $note->external_id = $this->data->external_id;
        $note->external_url = $this->data->external_url;
        $note->origin = $this->data->origin;
        $note->metadata = $this->data->metadata;
        $note->users_id = $this->user?->getId();

        $this->hydrateBillableSnapshot($note, $parent);

        $note->save();

        $this->persistLines($note, $this->data);
        $this->persistTaxLines($note, $this->data);

        return $note;
    }

    /** Inherits the frozen snapshot from the parent when there is one, else freezes it from the standalone billable. */
    private function hydrateBillableSnapshot(Invoice $note, ?Invoice $parent): void
    {
        if ($parent !== null) {
            $note->customer_organization_id = $parent->customer_organization_id;
            $note->billable_display_name = $parent->billable_display_name;
            $note->billable_legal_name = $parent->billable_legal_name;
            $note->billable_tax_id = $parent->billable_tax_id;
            $note->billable_email = $parent->billable_email;
            $note->billing_address_snapshot = $parent->billing_address_snapshot;
            $note->shipping_address_snapshot = $parent->shipping_address_snapshot;

            return;
        }

        $billable = $this->billable ?? throw new InvalidInvoiceTransitionException(
            "A standalone {$this->noteType->label()} (no parent invoice) requires a billable customer."
        );

        $note->customer_organization_id = $billable->getBillableId();
        $note->billable_display_name = $billable->getBillableDisplayName();
        $note->billable_legal_name = $billable->getBillableLegalName();
        $note->billable_tax_id = $billable->getBillableTaxId();
        $note->billable_email = $billable->getBillingEmail();
        $note->billing_address_snapshot = $billable->getBillingAddressArray();
    }
}
