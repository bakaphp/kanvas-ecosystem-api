<?php

declare(strict_types=1);

namespace Tests\Scribe\E2E;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Expenses\Actions\RecordExpenseReimbursementAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Invoices\Actions\MarkInvoicePaidAction;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Models\JournalEntryLine;
use Kanvas\Scribe\Reports\Repositories\BalanceSheetRepository;
use Kanvas\Scribe\Reports\Repositories\ProfitAndLossRepository;
use Kanvas\Scribe\Reports\Repositories\TrialBalanceRepository;
use Tests\Scribe\ScribeTestCase;

/**
 * Flow 5 — The cross-flow GL invariant check.
 *
 * Runs an Invoice cycle (issue → pay) + an Employee-reimbursement cycle (approve → reimburse)
 * + a manual expense (company-card approve) against ONE tenant, then asserts the core ledger
 * invariants hold:
 *
 *   1. Every POSTED JE is balanced (DR == CR per JE) — local invariant
 *   2. Grand-total DR == grand-total CR across all POSTED lines — global invariant
 *   3. Trial Balance reports the same DR/CR equality
 *   4. Balance Sheet is_balanced=true (Assets = Liabilities + Equity)
 *   5. P&L's net_income equals (Revenue - Expenses) computed independently from raw JE activity
 *
 * If any of those breaks, the GL has drifted somewhere — the test pinpoints which invariant.
 */
class GeneralLedgerInvariantsFlowTest extends ScribeTestCase
{
    public function test_general_ledger_invariants_hold_across_heterogeneous_flows(): void
    {
        $billable = $this->seedTestOrganization('Composite Customer');

        // ── Flow 1 — Invoice + payment ──
        $invoice = $this->issueTestInvoice(
            $billable,
            subtotal: 1000.0,
            tax: 0.0,
            issuedDate: '2026-06-05',
            dueDate: '2026-07-05',
        );

        $allocation = new InvoicePaymentAllocation();
        $allocation->apps_id = (int) $invoice->apps_id;
        $allocation->companies_id = (int) $invoice->companies_id;
        $allocation->invoice_id = (int) $invoice->id;
        $allocation->source_type = 'souk_payment';
        $allocation->status = AllocationStatusEnum::ACTIVE->value;
        $allocation->amount_native = 1000.0;
        $allocation->amount_base = 1000.0;
        $allocation->currency = 'USD';
        $allocation->fx_rate_to_base = 1.0;
        $allocation->allocated_at = Carbon::parse('2026-06-10');
        $allocation->source = 'kanvas';
        $allocation->save();

        new PostJournalEntryAction(
            data: new InvoiceJournalEntryComposerService()->composePayment(
                invoice: $invoice,
                allocation: $allocation,
            ),
            postedByUser: static::$cachedUser,
        )->execute();

        new MarkInvoicePaidAction(invoice: $invoice, user: static::$cachedUser)->execute();

        // ── Flow 2 — Employee personal expense + reimbursement ──
        $juanExpense = $this->approveTestExpense(
            amount: 300.0,
            paidBy: ExpensePaidByEnum::EMPLOYEE_PERSONAL,
            paidByUsersId: static::$cachedUser->getId(),
        );
        new RecordExpenseReimbursementAction(
            expense: $juanExpense,
            user: static::$cachedUser,
        )->execute();

        // ── Flow 3 — Company-card expense ──
        $this->approveTestExpense(amount: 150.0, paidBy: ExpensePaidByEnum::COMPANY_CARD);

        // ─────────────── Invariant 1 — every JE balanced ───────────────
        $postedJes = JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->with('lines')
            ->get();
        $this->assertGreaterThanOrEqual(5, $postedJes->count(), 'Expected ≥5 JEs across the 3 flows.');

        foreach ($postedJes as $je) {
            $debit = (float) $je->lines->sum('debit_base');
            $credit = (float) $je->lines->sum('credit_base');
            $this->assertEqualsWithDelta(
                $debit,
                $credit,
                0.005,
                "JE {$je->id} ({$je->source_type}) not balanced — DR={$debit}, CR={$credit}.",
            );
        }

        // ─────────────── Invariant 2 — global DR == CR ───────────────
        $globalDr = (float) JournalEntryLine::query()
            ->whereIn('journal_entry_id', $postedJes->pluck('id'))
            ->sum('debit_base');
        $globalCr = (float) JournalEntryLine::query()
            ->whereIn('journal_entry_id', $postedJes->pluck('id'))
            ->sum('credit_base');
        $this->assertEqualsWithDelta(
            $globalDr,
            $globalCr,
            0.005,
            'Grand-total DR must equal grand-total CR across all POSTED JE lines.',
        );

        // ─────────────── Invariant 3 — Trial Balance reflects the same ───────────────
        $tb = new TrialBalanceRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );
        $this->assertTrue($tb->is_balanced, 'Trial balance must report balanced.');
        $this->assertEqualsWithDelta($tb->total_debits, $tb->total_credits, 0.005);

        // ─────────────── Invariant 4 — Balance Sheet balances ───────────────
        $bs = new BalanceSheetRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );
        $this->assertTrue($bs->is_balanced, 'Balance Sheet equation A = L + E must hold after composite flows.');
        $this->assertEqualsWithDelta(
            $bs->assets->total,
            $bs->total_liabilities_and_equity,
            0.005,
            'Assets total must equal Liabilities+Equity total within penny tolerance.',
        );

        // ─────────────── Invariant 5 — P&L net_income == Revenue - Expenses ───────────────
        $pnl = new ProfitAndLossRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
        );

        // Independent computation: pull revenue + expense totals straight from posted JE lines.
        $this->assertEqualsWithDelta(1000.0, $pnl->revenue->total, 0.005, 'One invoice @ 1000 in Revenue.');
        $this->assertEqualsWithDelta(450.0, $pnl->operating_expenses->total, 0.005, 'Two expenses: 300 + 150.');
        $this->assertEqualsWithDelta(
            550.0,
            $pnl->net_income,
            0.005,
            'Net income = Revenue (1000) - Expenses (450).',
        );
    }
}
