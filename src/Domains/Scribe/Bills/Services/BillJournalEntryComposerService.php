<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Bills\Services;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;
use RuntimeException;
use Spatie\LaravelData\DataCollection;

/**
 * JE composer for Bill state transitions. Vendor-side mirror of InvoiceJournalEntryComposerService:
 *
 *   ReceiveBill (DR Expense + DR Input Tax / CR AP)
 *     DR Expense Account        {line_total - tax}   (one line per bill_line, expense_account_id required)
 *     DR Input Tax Receivable   {tax}                 (per tax_line jurisdiction, or fallback to header)
 *       CR Accounts Payable      {total}              (sub-ledger tagged by vendor)
 *
 *   ApplyPayment (DR AP / CR Cash) — composed per allocation when MarkBillPaidAction fires
 *     DR Accounts Payable       {amount}              (vendor sub-ledger)
 *       CR Cash — Checking       {amount}
 *
 * Tax routing: when invoice tax_lines exist with jurisdiction='DO', uses DR_ITBIS_RECEIVABLE; otherwise
 * uses INPUT_TAX_RECEIVABLE. Extensible per region the same way Invoice composer is.
 */
class BillJournalEntryComposerService
{
    public function __construct(
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    public function composeReceive(Bill $bill): JournalEntryData
    {
        $app = $bill->app;
        $company = $bill->company;
        $vendorBillableType = $bill->vendor_billable_type;
        $vendorBillableId = $bill->vendor_billable_id;
        $currency = $bill->currency;
        $fxRate = (float) $bill->fx_rate_to_base;

        $apAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::ACCOUNTS_PAYABLE);

        $bill->load(['lines', 'taxLines']);

        $lines = [];
        $sortOrder = 0;

        // DR Expense — per bill_line
        foreach ($bill->lines as $line) {
            if ($line->expense_account_id === null) {
                throw new RuntimeException(
                    "BillLine {$line->id} has no expense_account_id — cannot post receive JE. "
                    . 'Assign an expense account before receiving.'
                );
            }

            $netLineNative = (float) $line->line_total_native - (float) $line->tax_amount_native;
            $netLineBase = (float) $line->line_total_base - (float) $line->tax_amount_base;

            if ($netLineNative <= 0) {
                continue;
            }

            $lines[] = new JournalEntryLineData(
                account_id: $line->expense_account_id,
                debit_native: $netLineNative,
                credit_native: 0.0,
                debit_base: $netLineBase,
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: $sortOrder++,
                vendor_billable_type: $vendorBillableType,
                vendor_billable_id: $vendorBillableId,
                item_id: $line->item_id,
                class_id: $line->class_id,
                department_id: $line->department_id,
                memo: $line->description,
            );
        }

        // DR Input Tax Receivable
        $taxNative = (float) $bill->tax_native;
        $taxBase = (float) $bill->tax_base;
        if ($taxNative > 0) {
            $taxLines = $bill->taxLines;
            if ($taxLines->isNotEmpty()) {
                foreach ($taxLines as $taxLine) {
                    $taxAccount = $this->resolveInputTaxAccount($bill, $taxLine->jurisdiction);
                    $lines[] = new JournalEntryLineData(
                        account_id: $taxAccount->id,
                        debit_native: (float) $taxLine->tax_amount_native,
                        credit_native: 0.0,
                        debit_base: (float) $taxLine->tax_amount_base,
                        credit_base: 0.0,
                        currency: $currency,
                        fx_rate_to_base: $fxRate,
                        sort_order: $sortOrder++,
                        memo: "Bill {$bill->bill_number} — {$taxLine->name} (input)",
                    );
                }
            } else {
                $taxAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::INPUT_TAX_RECEIVABLE);
                $lines[] = new JournalEntryLineData(
                    account_id: $taxAccount->id,
                    debit_native: $taxNative,
                    credit_native: 0.0,
                    debit_base: $taxBase,
                    credit_base: 0.0,
                    currency: $currency,
                    fx_rate_to_base: $fxRate,
                    sort_order: $sortOrder++,
                    memo: "Bill {$bill->bill_number} — Input Tax",
                );
            }
        }

        // CR Accounts Payable — single line tagged by vendor
        $lines[] = new JournalEntryLineData(
            account_id: $apAccount->id,
            debit_native: 0.0,
            credit_native: (float) $bill->total_native,
            debit_base: 0.0,
            credit_base: (float) $bill->total_base,
            currency: $currency,
            fx_rate_to_base: $fxRate,
            sort_order: $sortOrder,
            vendor_billable_type: $vendorBillableType,
            vendor_billable_id: $vendorBillableId,
            memo: "Bill {$bill->bill_number} — AP",
        );

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $bill->received_date ?? Carbon::now(),
            sourceType: 'bill',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $bill->id,
            memo: "Bill {$bill->bill_number} received",
            source: $bill->source,
            externalId: $bill->external_id,
            origin: $bill->origin,
        );
    }

    public function composePayment(
        Bill $bill,
        BillPaymentAllocation $allocation,
        AccountSubTypeEnum $cashAccountSubType = AccountSubTypeEnum::CASH_CHECKING,
    ): JournalEntryData {
        $app = $bill->app;
        $company = $bill->company;

        $apAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::ACCOUNTS_PAYABLE);
        $cashAccount = $this->accountResolver->bySubType($app, $company, $cashAccountSubType);

        $amountNative = (float) $allocation->amount_native;
        $amountBase = (float) $allocation->amount_base;
        $currency = $allocation->currency;
        $fxRate = (float) $allocation->fx_rate_to_base;

        $lines = [
            // DR Accounts Payable (clears the vendor sub-ledger)
            new JournalEntryLineData(
                account_id: $apAccount->id,
                debit_native: $amountNative,
                credit_native: 0.0,
                debit_base: $amountBase,
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 0,
                vendor_billable_type: $bill->vendor_billable_type,
                vendor_billable_id: $bill->vendor_billable_id,
                memo: "Bill {$bill->bill_number} — AP cleared",
            ),
            // CR Cash
            new JournalEntryLineData(
                account_id: $cashAccount->id,
                debit_native: 0.0,
                credit_native: $amountNative,
                debit_base: 0.0,
                credit_base: $amountBase,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 1,
                memo: "Bill {$bill->bill_number} — Payment sent",
            ),
        ];

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $allocation->allocated_at,
            sourceType: 'bill_payment',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $allocation->payment_id,
            memo: "Bill {$bill->bill_number} payment",
            source: $allocation->source,
            externalId: $allocation->external_id,
            origin: JournalEntryOriginEnum::KANVAS,
        );
    }

    private function resolveInputTaxAccount(Bill $bill, ?string $jurisdiction): Account
    {
        $app = $bill->app;
        $company = $bill->company;

        if ($jurisdiction === 'DO') {
            $drAccount = $this->accountResolver->bySubTypeOrNull($app, $company, AccountSubTypeEnum::DR_ITBIS_RECEIVABLE);
            if ($drAccount !== null) {
                return $drAccount;
            }
        }

        return $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::INPUT_TAX_RECEIVABLE);
    }
}
