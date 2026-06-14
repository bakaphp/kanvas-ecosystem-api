<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesReceipts\Services;

use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;
use Kanvas\Scribe\SalesReceipts\Models\SalesReceipt;
use Spatie\LaravelData\DataCollection;

/**
 * Composes the JE shape for sales receipts.
 *
 *   Create (DR Cash / CR Revenue + CR Sales Tax Payable) — no AR intermediate
 *     DR Cash (account on the receipt OR system cash_checking fallback)   {total}
 *       CR Service Revenue (per-line income_account_id OR system service_revenue fallback)   {net = subtotal - discount}
 *       CR Sales Tax Payable   {tax}    (single line — no per-jurisdiction split in PR 4; that comes with tax_lines)
 *
 *   Void — mirror DR↔CR swap, composed inline by VoidSalesReceiptAction (parallel to VoidInvoiceAction).
 *
 * @see plan §3.5 sub-ledger → GL auto-posting (sales receipts)
 * @see plan §11.3 — QuickShop $49 Pro Plan worked example
 */
class SalesReceiptJournalEntryComposerService
{
    public function __construct(
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    public function composeCreate(SalesReceipt $receipt): JournalEntryData
    {
        $app = $receipt->app;
        $company = $receipt->company;
        $billableType = $receipt->billable_type;
        $billableId = $receipt->billable_id;

        $cashAccount = $this->resolveCashAccount($receipt);
        $defaultRevenueAccount = $this->accountResolver->bySubType(
            $app,
            $company,
            AccountSubTypeEnum::SERVICE_REVENUE,
        );

        $netRevenueNative = (float) $receipt->subtotal_native - (float) $receipt->discount_native;
        $netRevenueBase = (float) $receipt->subtotal_base - (float) $receipt->discount_base;
        $totalNative = (float) $receipt->total_native;
        $totalBase = (float) $receipt->total_base;
        $taxNative = (float) $receipt->tax_native;
        $taxBase = (float) $receipt->tax_base;
        $currency = $receipt->currency;
        $fxRate = (float) $receipt->fx_rate_to_base;

        $lines = [
            // DR Cash
            new JournalEntryLineData(
                account_id: $cashAccount->id,
                debit_native: $totalNative,
                credit_native: 0.0,
                debit_base: $totalBase,
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 0,
                customer_billable_type: $billableType,
                customer_billable_id: $billableId,
                memo: "Sales Receipt {$receipt->receipt_number} — Cash",
            ),
            // CR Revenue (net of discount) — for PR 4, single fallback line; per-item splits land in PR 5+
            new JournalEntryLineData(
                account_id: $defaultRevenueAccount->id,
                debit_native: 0.0,
                credit_native: $netRevenueNative,
                debit_base: 0.0,
                credit_base: $netRevenueBase,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 1,
                customer_billable_type: $billableType,
                customer_billable_id: $billableId,
                memo: "Sales Receipt {$receipt->receipt_number} — Revenue",
            ),
        ];

        // CR Sales Tax Payable (single line in PR 4 — multi-jurisdiction comes with invoice_tax_lines pattern in PR 5+)
        if ($taxNative > 0) {
            $taxAccount = $this->accountResolver->bySubType(
                $app,
                $company,
                AccountSubTypeEnum::SALES_TAX_PAYABLE,
            );
            $lines[] = new JournalEntryLineData(
                account_id: $taxAccount->id,
                debit_native: 0.0,
                credit_native: $taxNative,
                debit_base: 0.0,
                credit_base: $taxBase,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 2,
                memo: "Sales Receipt {$receipt->receipt_number} — Sales Tax",
            );
        }

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $receipt->receipt_date,
            sourceType: 'sales_receipt',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $receipt->id,
            memo: "Sales Receipt {$receipt->receipt_number}",
            source: $receipt->source,
            externalId: $receipt->external_id,
            origin: $receipt->origin,
        );
    }

    /**
     * Cash account selection:
     *   1. If the receipt has an explicit cash_account_id, use it (typically pointing at a Mercury/checking account).
     *   2. Otherwise fall back to the system CASH_CHECKING account.
     */
    private function resolveCashAccount(SalesReceipt $receipt): Account
    {
        if ($receipt->cash_account_id !== null) {
            $explicit = Account::query()
                ->where('id', $receipt->cash_account_id)
                ->where('apps_id', $receipt->apps_id)
                ->where('companies_id', $receipt->companies_id)
                ->where('is_active', true)
                ->first();

            if ($explicit !== null) {
                return $explicit;
            }
        }

        return $this->accountResolver->bySubType(
            $receipt->app,
            $receipt->company,
            AccountSubTypeEnum::CASH_CHECKING,
        );
    }
}
