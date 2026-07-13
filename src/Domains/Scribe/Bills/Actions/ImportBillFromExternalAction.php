<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\Enums\BillCollectionStateEnum;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;

/**
 * Materializes a bill that was already recorded in an external system of record (an ERP such as
 * Acumatica) as a first-class Scribe document — WITHOUT posting a journal entry. The AP-side
 * counterpart of {@see \Kanvas\Scribe\Invoices\Actions\ImportInvoiceFromExternalAction}; see that
 * class for the full rationale (external GL is authoritative, re-posting via ReceiveBillAction would
 * double-count the AP control account).
 *
 * Lands the bill straight in its terminal state (RECEIVED or PAID) with the source's authoritative
 * paid/balance figures and freezes the vendor snapshot, so per-bill AP aging has something to read.
 * Idempotent on (apps_id, companies_id, source, external_id) — a re-run refreshes the mutable
 * financial state only.
 */
class ImportBillFromExternalAction
{
    private const float PAID_TOLERANCE = 0.005;

    /**
     * @param float $paidNative amount already paid against the bill in the source system (native currency)
     */
    public function __construct(
        public readonly BillData $data,
        public readonly float $paidNative = 0.0,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): Bill
    {
        return DB::connection('accounting')->transaction(function (): Bill {
            $existing = $this->findExisting();

            $bill = $existing ?? new CreateBillAction($this->data, $this->user)->execute();

            if ($existing === null) {
                $this->freezeVendorSnapshot($bill);
                $this->setDates($bill);
            }

            $this->applyFinancialState($bill);
            $bill->save();

            return $bill->refresh();
        });
    }

    private function applyFinancialState(Bill $bill): void
    {
        $fxRate = (float) $this->data->fx_rate_to_base;
        $paidNative = round($this->paidNative, 4);
        $totalNative = (float) $bill->total_native;
        $balanceNative = round(max($totalNative - $paidNative, 0.0), 4);

        $bill->paid_native = $paidNative;
        $bill->paid_base = round($paidNative * $fxRate, 4);
        $bill->balance_due_native = $balanceNative;
        $bill->balance_due_base = round($balanceNative * $fxRate, 4);

        $isPaid = $balanceNative <= self::PAID_TOLERANCE;
        $bill->document_status = $isPaid
            ? BillDocumentStatusEnum::PAID
            : BillDocumentStatusEnum::RECEIVED;
        $bill->collection_state = $isPaid ? null : $this->collectionState($bill);

        if ($isPaid && $bill->paid_at === null) {
            $bill->paid_at = Carbon::now();
        }
    }

    private function collectionState(Bill $bill): BillCollectionStateEnum
    {
        $dueDate = $bill->due_date;

        if ($dueDate !== null && $dueDate->isBefore(Carbon::today())) {
            return BillCollectionStateEnum::OVERDUE;
        }

        return BillCollectionStateEnum::CURRENT;
    }

    private function freezeVendorSnapshot(Bill $bill): void
    {
        $vendor = $this->data->vendor;

        if ($vendor === null) {
            return;
        }

        $bill->vendor_organization_id = $vendor->getPayeeId();
        $bill->vendor_display_name = $bill->vendor_display_name ?? $vendor->getPayeeDisplayName();
        $bill->vendor_legal_name = $bill->vendor_legal_name ?? $vendor->getPayeeLegalName();
        $bill->vendor_tax_id = $bill->vendor_tax_id ?? $vendor->getPayeeTaxId();
        $bill->vendor_email = $bill->vendor_email ?? $vendor->getPayeeEmail();
        $bill->vendor_address_snapshot = $bill->vendor_address_snapshot ?? $vendor->getPayeeAddressArray();
    }

    private function setDates(Bill $bill): void
    {
        if ($bill->bill_date === null) {
            $bill->bill_date = Carbon::today();
        }

        if ($bill->received_date === null) {
            $bill->received_date = $bill->bill_date;
        }

        if ($bill->due_date === null) {
            $bill->due_date = $bill->bill_date->copy()->addDays((int) ($bill->net_terms_days ?? 0));
        }
    }

    private function findExisting(): ?Bill
    {
        if ($this->data->external_id === null || $this->data->external_id === '') {
            return null;
        }

        return Bill::query()
            ->where('apps_id', $this->data->app->getId())
            ->where('companies_id', $this->data->company->getId())
            ->where('source', $this->data->source)
            ->where('external_id', $this->data->external_id)
            ->where('origin', JournalEntryOriginEnum::EXTERNAL->value)
            ->where('is_deleted', false)
            ->first();
    }
}
