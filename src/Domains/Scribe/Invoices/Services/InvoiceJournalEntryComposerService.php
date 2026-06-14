<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Services;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryData;
use Kanvas\Scribe\Ledger\DataTransferObject\JournalEntryLineData;
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
        $billableType = $invoice->billable_type;
        $billableId = $invoice->billable_id;

        $arAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);
        $revenueAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SERVICE_REVENUE);

        $netRevenueNative = (float) $invoice->subtotal_native - (float) $invoice->discount_native;
        $netRevenueBase = (float) $invoice->subtotal_base - (float) $invoice->discount_base;
        $totalNative = (float) $invoice->total_native;
        $totalBase = (float) $invoice->total_base;
        $taxNative = (float) $invoice->tax_native;
        $taxBase = (float) $invoice->tax_base;
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

        // CR Sales Tax Payable — one line per tax jurisdiction when tax_lines are populated;
        // single fallback line when only the header tax_native is set.
        if ($taxNative > 0) {
            $taxLines = $invoice->taxLines;
            if ($taxLines->isNotEmpty()) {
                $sortOrder = 2;
                foreach ($taxLines as $taxLine) {
                    $taxAccount = $this->resolveTaxAccount($invoice, $taxLine->jurisdiction);
                    $lines[] = new JournalEntryLineData(
                        account_id: $taxAccount->id,
                        debit_native: 0.0,
                        credit_native: (float) $taxLine->tax_amount_native,
                        debit_base: 0.0,
                        credit_base: (float) $taxLine->tax_amount_base,
                        currency: $currency,
                        fx_rate_to_base: $fxRate,
                        sort_order: $sortOrder++,
                        memo: "Invoice {$invoice->invoice_number} — {$taxLine->name}",
                    );
                }
            } else {
                $taxAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SALES_TAX_PAYABLE);
                $lines[] = new JournalEntryLineData(
                    account_id: $taxAccount->id,
                    debit_native: 0.0,
                    credit_native: $taxNative,
                    debit_base: 0.0,
                    credit_base: $taxBase,
                    currency: $currency,
                    fx_rate_to_base: $fxRate,
                    sort_order: 2,
                    memo: "Invoice {$invoice->invoice_number} — Sales Tax",
                );
            }
        }

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
                customer_billable_type: $invoice->billable_type,
                customer_billable_id: $invoice->billable_id,
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
     * The credit_note Invoice row carries POSITIVE amounts; the JE direction is what makes it a credit.
     */
    public function composeCreditNote(Invoice $creditNote): JournalEntryData
    {
        $app = $creditNote->app;
        $company = $creditNote->company;
        $billableType = $creditNote->billable_type;
        $billableId = $creditNote->billable_id;

        $arAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::ACCOUNTS_RECEIVABLE);
        $revenueAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SERVICE_REVENUE);

        $netRevenueNative = (float) $creditNote->subtotal_native - (float) $creditNote->discount_native;
        $netRevenueBase = (float) $creditNote->subtotal_base - (float) $creditNote->discount_base;
        $totalNative = (float) $creditNote->total_native;
        $totalBase = (float) $creditNote->total_base;
        $taxNative = (float) $creditNote->tax_native;
        $taxBase = (float) $creditNote->tax_base;
        $currency = $creditNote->currency;
        $fxRate = (float) $creditNote->fx_rate_to_base;

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

        if ($taxNative > 0) {
            $taxLines = $creditNote->taxLines;
            if ($taxLines->isNotEmpty()) {
                $sortOrder = 1;
                foreach ($taxLines as $taxLine) {
                    $taxAccount = $this->resolveTaxAccount($creditNote, $taxLine->jurisdiction);
                    $lines[] = new JournalEntryLineData(
                        account_id: $taxAccount->id,
                        debit_native: (float) $taxLine->tax_amount_native,
                        credit_native: 0.0,
                        debit_base: (float) $taxLine->tax_amount_base,
                        credit_base: 0.0,
                        currency: $currency,
                        fx_rate_to_base: $fxRate,
                        sort_order: $sortOrder++,
                        memo: "Credit Note {$creditNote->invoice_number} — {$taxLine->name} reversal",
                    );
                }
            } else {
                $taxAccount = $this->accountResolver->bySubType($app, $company, AccountSubTypeEnum::SALES_TAX_PAYABLE);
                $lines[] = new JournalEntryLineData(
                    account_id: $taxAccount->id,
                    debit_native: $taxNative,
                    credit_native: 0.0,
                    debit_base: $taxBase,
                    credit_base: 0.0,
                    currency: $currency,
                    fx_rate_to_base: $fxRate,
                    sort_order: 1,
                    memo: "Credit Note {$creditNote->invoice_number} — Sales Tax reversal",
                );
            }
        }

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
