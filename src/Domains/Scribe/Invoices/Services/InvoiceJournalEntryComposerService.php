<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntry as JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLine as JournalEntryLineData;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Services\AccountResolverService;
use Spatie\LaravelData\DataCollection;

/**
 * Composes the JE shape for every state transition on an Invoice.
 *
 * The Invoice carries apps_id + companies_id; this composer uses the inherited `app()` and `company()`
 * BelongsTo relations (from KanvasModelTrait) to derive scope — callers never pass App + Company explicitly.
 *
 * Shapes (per plan §3.5):
 *
 *   IssueInvoice (DR AR / CR Revenue + CR Sales Tax Payable)
 *     DR Accounts Receivable     {total}
 *       CR Service Revenue         {subtotal - discount}
 *       CR Sales Tax Payable       {tax}        (one line per tax jurisdiction when invoice_tax_lines populated;
 *                                                 single fallback line when not)
 *
 *   ApplyPayment (DR Cash / CR AR) — composed per allocation when MarkInvoicePaidAction fires
 *     DR Cash — Checking          {amount}
 *       CR Accounts Receivable     {amount}
 *
 *   Void (reverse the original issue JE — mirror DR/CR swap) — composed inline by VoidInvoiceAction.
 *
 * @see plan §3.5 sub-ledger → GL auto-posting table
 */
class InvoiceJournalEntryComposerService
{
    public function __construct(
        protected readonly AccountResolverService $accountResolver = new AccountResolverService(),
    ) {
    }

    public function composeIssue(Invoice $invoice): JournalEntryData
    {
        $app = $invoice->app;
        $company = $invoice->company;
        $billableType = 'organization'; // Phase 4: Org-only
        $billableId = $invoice->customer_organization_id;

        $arAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);
        $revenueAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SERVICE_REVENUE);

        $netRevenueNative = (float) $invoice->subtotal_native - (float) $invoice->discount_native;
        $netRevenueBase = (float) $invoice->subtotal_base - (float) $invoice->discount_base;
        $totalNative = (float) $invoice->total_native;
        $totalBase = (float) $invoice->total_base;
        $currency = $invoice->currency;
        $fxRate = (float) $invoice->fx_rate_to_base;

        $lines = [
            // DR Accounts Receivable
            new JournalEntryLineData(
                account_id: $arAccount->id,
                debit_native: $totalNative,
                credit_native: 0.0,
                debit_base: $totalBase,
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 0,
                customer_billable_type: $billableType,
                customer_billable_id: $billableId,
                memo: "Invoice {$invoice->invoice_number} — AR",
            ),
            // CR Revenue (net of discount)
            new JournalEntryLineData(
                account_id: $revenueAccount->id,
                debit_native: 0.0,
                credit_native: $netRevenueNative,
                debit_base: 0.0,
                credit_base: $netRevenueBase,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 1,
                customer_billable_type: $billableType,
                customer_billable_id: $billableId,
                memo: "Invoice {$invoice->invoice_number} — Revenue",
            ),
        ];

        array_push($lines, ...$this->buildTaxLines($invoice, credit: true, memoSuffix: '', startSortOrder: 2));

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $invoice->issued_date ?? Carbon::now(),
            sourceType: 'invoice',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $invoice->id,
            memo: "Invoice {$invoice->invoice_number} issued",
            source: $invoice->source,
            externalId: $invoice->external_id,
            origin: $invoice->origin,
        );
    }

    public function composePayment(
        Invoice $invoice,
        InvoicePaymentAllocation $allocation,
        AccountSubTypeEnum $cashAccountSubType = AccountSubTypeEnum::CASH_CHECKING,
    ): JournalEntryData {
        $app = $invoice->app;
        $company = $invoice->company;

        $arAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);
        $cashAccount = $this->accountResolver->bySubType($app, $company, $cashAccountSubType);

        $amountNative = (float) $allocation->amount_native;
        $amountBase = (float) $allocation->amount_base;
        $currency = $allocation->currency;
        $fxRate = (float) $allocation->fx_rate_to_base;

        $lines = [
            // DR Cash
            new JournalEntryLineData(
                account_id: $cashAccount->id,
                debit_native: $amountNative,
                credit_native: 0.0,
                debit_base: $amountBase,
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 0,
                memo: "Invoice {$invoice->invoice_number} — Payment received",
            ),
            // CR Accounts Receivable
            new JournalEntryLineData(
                account_id: $arAccount->id,
                debit_native: 0.0,
                credit_native: $amountNative,
                debit_base: 0.0,
                credit_base: $amountBase,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: 1,
                customer_billable_type: 'organization',
                customer_billable_id: $invoice->customer_organization_id,
                memo: "Invoice {$invoice->invoice_number} — AR cleared",
            ),
        ];

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $allocation->allocated_at,
            sourceType: 'payment',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $allocation->payment_id,
            memo: "Invoice {$invoice->invoice_number} payment",
            source: $allocation->source,
            externalId: $allocation->external_id,
            origin: JournalEntryOriginEnum::KANVAS,
        );
    }

    /**
     * Credit-note JE — inverse of the issue JE. Reduces revenue, reduces tax-payable, reduces AR.
     *
     *   DR Service Revenue        {net of discount}      (reverses earlier revenue recognition)
     *   DR Sales Tax Payable      {tax}                   (reverses the tax liability)
     *     CR Accounts Receivable   {total}                (reduces customer balance)
     *
     * When a line carries its own account_id (e.g. a back-end-rebate's Control Acct#), that line debits
     * its own account instead of Service Revenue — one JE line per distinct account, lines sharing an
     * account are summed together. Falls back to the single Service-Revenue line when no line overrides it.
     *
     * The credit_note Invoice row carries POSITIVE amounts; the JE direction is what makes it a credit.
     */
    public function composeCreditNote(Invoice $creditNote): JournalEntryData
    {
        $app = $creditNote->app;
        $company = $creditNote->company;
        $billableType = 'organization'; // Phase 4: Org-only
        $billableId = $creditNote->customer_organization_id;

        $arAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);

        $totalNative = (float) $creditNote->total_native;
        $totalBase = (float) $creditNote->total_base;
        $currency = $creditNote->currency;
        $fxRate = (float) $creditNote->fx_rate_to_base;

        $codedLines = $creditNote->lines->whereNotNull('account_id');

        if ($codedLines->isNotEmpty()) {
            $lines = $this->composeCreditNoteDebitLinesByAccount(
                $creditNote,
                $codedLines,
                $billableType,
                $billableId,
                $currency,
                $fxRate,
            );
        } else {
            $revenueAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SERVICE_REVENUE);
            $netRevenueNative = (float) $creditNote->subtotal_native - (float) $creditNote->discount_native;
            $netRevenueBase = (float) $creditNote->subtotal_base - (float) $creditNote->discount_base;

            $lines = [
                // DR Revenue
                new JournalEntryLineData(
                    account_id: $revenueAccount->id,
                    debit_native: $netRevenueNative,
                    credit_native: 0.0,
                    debit_base: $netRevenueBase,
                    credit_base: 0.0,
                    currency: $currency,
                    fx_rate_to_base: $fxRate,
                    sort_order: 0,
                    customer_billable_type: $billableType,
                    customer_billable_id: $billableId,
                    memo: "Credit Note {$creditNote->invoice_number} — Revenue reversal",
                ),
            ];
        }

        array_push($lines, ...$this->buildTaxLines($creditNote, credit: false, memoSuffix: ' reversal', startSortOrder: 1));

        // CR Accounts Receivable
        $lines[] = new JournalEntryLineData(
            account_id: $arAccount->id,
            debit_native: 0.0,
            credit_native: $totalNative,
            debit_base: 0.0,
            credit_base: $totalBase,
            currency: $currency,
            fx_rate_to_base: $fxRate,
            sort_order: count($lines),
            customer_billable_type: $billableType,
            customer_billable_id: $billableId,
            memo: "Credit Note {$creditNote->invoice_number} — AR reduction",
        );

        return new JournalEntryData(
            app: $app,
            company: $company,
            postedAt: $creditNote->issued_date ?? Carbon::now(),
            sourceType: 'credit_note',
            lines: new DataCollection(JournalEntryLineData::class, $lines),
            sourceId: $creditNote->id,
            memo: "Credit Note {$creditNote->invoice_number} issued",
            source: $creditNote->source,
            externalId: $creditNote->external_id,
            origin: $creditNote->origin,
        );
    }

    /**
     * One DR JE line per distinct account_id among the coded lines (lines sharing an account are summed).
     *
     * @return array<int, JournalEntryLineData>
     */
    private function composeCreditNoteDebitLinesByAccount(
        Invoice $creditNote,
        Collection $codedLines,
        string $billableType,
        ?int $billableId,
        string $currency,
        float $fxRate,
    ): array {
        $lines = [];
        $sortOrder = 0;

        foreach ($codedLines->groupBy('account_id') as $accountId => $groupLines) {
            $account = Account::query()->where('id', $accountId)->firstOrFail();

            $lines[] = new JournalEntryLineData(
                account_id: $account->id,
                debit_native: (float) $groupLines->sum('line_total_native'),
                credit_native: 0.0,
                debit_base: (float) $groupLines->sum('line_total_base'),
                credit_base: 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: $sortOrder++,
                customer_billable_type: $billableType,
                customer_billable_id: $billableId,
                memo: "Credit Note {$creditNote->invoice_number} — {$account->name}",
            );
        }

        return $lines;
    }

    /**
     * Shared by composeIssue (credit: true) and composeCreditNote (credit: false, " reversal" suffix):
     * one tax-payable line per jurisdiction when tax_lines are populated, else a single fallback line
     * off the header tax_native/tax_base. Empty array when there's no tax to book.
     *
     * @return array<int, JournalEntryLineData>
     */
    private function buildTaxLines(Invoice $invoice, bool $credit, string $memoSuffix, int $startSortOrder): array
    {
        $taxNative = (float) $invoice->tax_native;

        if ($taxNative <= 0) {
            return [];
        }

        $label = $invoice->isCreditNote() ? 'Credit Note' : 'Invoice';
        $currency = $invoice->currency;
        $fxRate = (float) $invoice->fx_rate_to_base;
        $taxLines = $invoice->taxLines;

        if ($taxLines->isEmpty()) {
            $taxAccount = $this->accountResolver->bySubType($invoice->app, $invoice->company, AccountSubTypeEnum::SALES_TAX_PAYABLE);

            return [
                new JournalEntryLineData(
                    account_id: $taxAccount->id,
                    debit_native: $credit ? 0.0 : $taxNative,
                    credit_native: $credit ? $taxNative : 0.0,
                    debit_base: $credit ? 0.0 : (float) $invoice->tax_base,
                    credit_base: $credit ? (float) $invoice->tax_base : 0.0,
                    currency: $currency,
                    fx_rate_to_base: $fxRate,
                    sort_order: $startSortOrder,
                    memo: "{$label} {$invoice->invoice_number} — Sales Tax{$memoSuffix}",
                ),
            ];
        }

        $lines = [];
        $sortOrder = $startSortOrder;
        foreach ($taxLines as $taxLine) {
            $taxAccount = $this->resolveTaxAccount($invoice, $taxLine->jurisdiction);
            $lines[] = new JournalEntryLineData(
                account_id: $taxAccount->id,
                debit_native: $credit ? 0.0 : (float) $taxLine->tax_amount_native,
                credit_native: $credit ? (float) $taxLine->tax_amount_native : 0.0,
                debit_base: $credit ? 0.0 : (float) $taxLine->tax_amount_base,
                credit_base: $credit ? (float) $taxLine->tax_amount_base : 0.0,
                currency: $currency,
                fx_rate_to_base: $fxRate,
                sort_order: $sortOrder++,
                memo: "{$label} {$invoice->invoice_number} — {$taxLine->name}{$memoSuffix}",
            );
        }

        return $lines;
    }

    /**
     * Map a tax-line's jurisdiction to the correct payable GL account.
     *   - DR jurisdiction with ITBIS-shaped rate → dr_itbis_payable
     *   - Anything else → sales_tax_payable (generic US-default)
     *
     * Extends naturally as we add more regional validators (MX/BR/AR/EU).
     */
    private function resolveTaxAccount(Invoice $invoice, ?string $jurisdiction): Account
    {
        $app = $invoice->app;
        $company = $invoice->company;

        if ($jurisdiction === 'DO') {
            $drAccount = $this->accountResolver->bySubTypeOrNull($app, $company, AccountSubTypeEnum::DR_ITBIS_PAYABLE);
            if ($drAccount !== null) {
                return $drAccount;
            }
        }

        return $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SALES_TAX_PAYABLE);
    }
}
