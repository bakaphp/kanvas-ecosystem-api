<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\Enums\InvoiceCollectionStateEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;

/**
 * Materializes an invoice that was already issued in an external system of record (an ERP such as
 * Acumatica) as a first-class Scribe document — WITHOUT posting a journal entry.
 *
 * This is the sub-ledger analogue of importing GL batches with origin=EXTERNAL: the source's books
 * are authoritative (see Scribe invariant #4). When the external GL is imported separately, its AR
 * control-account balance already exists, so issuing this invoice through the normal
 * IssueInvoiceAction — which posts a DR AR / CR Revenue JE — would double-count. Instead we land the
 * document straight in its terminal state (ISSUED or PAID) with the source's authoritative
 * paid/balance figures and freeze the billable snapshot, purely so per-invoice AR aging and
 * collections have something to read.
 *
 * Idempotent on (apps_id, companies_id, source, external_id): a re-run refreshes the mutable
 * financial state (paid / balance / status / collection_state) so aging stays current as payments
 * are applied upstream, but never re-creates the row or its lines.
 *
 * NOT a Kanvas-native transition — it deliberately bypasses the draft→issued state machine because
 * there is no local transition to make; the invoice was issued elsewhere. Native invoices still go
 * through CreateInvoiceAction → IssueInvoiceAction with origin=KANVAS.
 */
class ImportInvoiceFromExternalAction
{
    private const float PAID_TOLERANCE = 0.005;

    /**
     * @param float $paidNative amount already applied against the invoice in the source system (native currency)
     */
    public function __construct(
        public readonly InvoiceData $data,
        public readonly float $paidNative = 0.0,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Invoice
    {
        return DB::connection('accounting')->transaction(function (): Invoice {
            $existing = $this->findExisting();

            $invoice = $existing ?? new CreateInvoiceAction($this->data, $this->user)->execute();

            if ($existing === null) {
                $this->freezeBillableSnapshot($invoice);
                $this->setDates($invoice);
            }

            $this->applyFinancialState($invoice);
            $invoice->save();

            return $invoice->refresh();
        });
    }

    private function applyFinancialState(Invoice $invoice): void
    {
        $fxRate = (float) $this->data->fx_rate_to_base;
        $paidNative = round($this->paidNative, 4);
        $totalNative = (float) $invoice->total_native;
        $balanceNative = round(max($totalNative - $paidNative, 0.0), 4);

        $invoice->paid_native = $paidNative;
        $invoice->paid_base = round($paidNative * $fxRate, 4);
        $invoice->balance_due_native = $balanceNative;
        $invoice->balance_due_base = round($balanceNative * $fxRate, 4);

        $isPaid = $balanceNative <= self::PAID_TOLERANCE;
        $invoice->document_status = $isPaid
            ? InvoiceDocumentStatusEnum::PAID
            : InvoiceDocumentStatusEnum::ISSUED;
        $invoice->collection_state = $isPaid ? null : $this->collectionState($invoice);

        if ($isPaid && $invoice->paid_at === null) {
            $invoice->paid_at = Carbon::now();
        }
    }

    private function collectionState(Invoice $invoice): InvoiceCollectionStateEnum
    {
        $dueDate = $invoice->due_date;

        if ($dueDate !== null && $dueDate->isBefore(Carbon::today())) {
            return InvoiceCollectionStateEnum::OVERDUE;
        }

        return InvoiceCollectionStateEnum::CURRENT;
    }

    private function freezeBillableSnapshot(Invoice $invoice): void
    {
        $billable = $this->data->billable;

        if ($billable === null) {
            return;
        }

        $invoice->customer_organization_id = $billable->getBillableId();
        $invoice->billable_display_name = $invoice->billable_display_name ?? $billable->getBillableDisplayName();
        $invoice->billable_legal_name = $invoice->billable_legal_name ?? $billable->getBillableLegalName();
        $invoice->billable_tax_id = $invoice->billable_tax_id ?? $billable->getBillableTaxId();
        $invoice->billable_email = $invoice->billable_email ?? $billable->getBillingEmail();
        $invoice->billing_address_snapshot = $invoice->billing_address_snapshot ?? $billable->getBillingAddressArray();
    }

    private function setDates(Invoice $invoice): void
    {
        if ($invoice->issued_date === null) {
            $invoice->issued_date = Carbon::today();
        }

        if ($invoice->due_date === null) {
            $invoice->due_date = $invoice->issued_date->copy()->addDays((int) ($invoice->net_terms_days ?? 0));
        }
    }

    private function findExisting(): ?Invoice
    {
        if ($this->data->external_id === null || $this->data->external_id === '') {
            return null;
        }

        return Invoice::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('source', $this->data->source)
            ->where('external_id', $this->data->external_id)
            ->where('origin', JournalEntryOriginEnum::EXTERNAL->value)
            ->where('is_deleted', false)
            ->first();
    }
}
