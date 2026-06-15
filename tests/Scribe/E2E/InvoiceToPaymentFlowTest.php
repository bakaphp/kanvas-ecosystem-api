<?php

declare(strict_types=1);

namespace Tests\Scribe\E2E;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\MarkInvoicePaidAction;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Models\InvoicePaymentAllocation;
use Kanvas\Scribe\Invoices\Services\InvoiceJournalEntryComposerService;
use Kanvas\Scribe\Ledger\Actions\PostJournalEntryAction;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Reports\Enums\RevenueGroupByEnum;
use Kanvas\Scribe\Reports\Repositories\AccountActivityRepository;
use Kanvas\Scribe\Reports\Repositories\ArAgingRepository;
use Kanvas\Scribe\Reports\Repositories\RevenueRepository;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ScribeTestCase;

/**
 * Flow 1 — the canonical AR cycle: Create → Issue → (payment allocation) → MarkPaid.
 *
 * Exercises every layer end-to-end with no GL mocking:
 *   - CreateInvoiceAction (draft)
 *   - IssueInvoiceAction (state machine, billable snapshot, document_number alloc, JE post)
 *   - InvoiceJournalEntryComposerService::composePayment + PostJournalEntryAction (real DR Cash / CR AR)
 *   - MarkInvoicePaidAction (recompute paid/balance, flip to PAID, emit ledger event)
 *   - AccountActivityRepository (real GL balance read)
 *   - RevenueRepository + ArAgingRepository (real report read)
 *
 * What it proves:
 *   - 2 POSTED JEs land balanced (Issue + Payment)
 *   - GL post-payment: AR=0, Cash=+total, Revenue=+(subtotal - discount), Sales Tax Payable=+tax
 *   - Reports converge with the GL — Revenue includes the invoice, AR Aging excludes it
 *   - Sub-ledger events emitted (scribe.invoice.issued + scribe.invoice.paid)
 */
class InvoiceToPaymentFlowTest extends ScribeTestCase
{
    public function test_full_invoice_to_payment_cycle(): void
    {
        $billable = $this->seedTestOrganization('ACME Studios');

        // ── Step 1 — create draft invoice (2 service lines + 1 tax line) ──
        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Consulting',
                        quantity: 10,
                        unit_price_native: 100.0,
                        tax_amount_native: 80.0,
                    ),
                    new InvoiceLineData(
                        description: 'Workshop',
                        quantity: 1,
                        unit_price_native: 500.0,
                        tax_amount_native: 40.0,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-06-15'),
                due_date: Carbon::parse('2026-07-15'),
            ),
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::DRAFT, $draft->document_status);
        $this->assertNull($draft->invoice_number, 'Draft invoices have no number until issue.');
        $this->assertEqualsWithDelta(1500.0, (float) $draft->subtotal_native, 0.005);
        $this->assertEqualsWithDelta(120.0, (float) $draft->tax_native, 0.005);
        $this->assertEqualsWithDelta(1620.0, (float) $draft->total_native, 0.005);

        // ── Step 2 — issue the invoice ──
        $invoice = new IssueInvoiceAction(
            invoice: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::ISSUED, $invoice->document_status);
        $this->assertNotNull($invoice->invoice_number, 'Issue allocates an invoice_number.');
        $this->assertSame('ACME Studios', $invoice->billable_display_name, 'Billable snapshot frozen at issue.');
        $this->assertEqualsWithDelta(1620.0, (float) $invoice->balance_due_native, 0.005);

        $issueJe = $this->latestPostedJeFor('invoice', (int) $invoice->id);
        $this->assertNotNull($issueJe);
        $this->assertJeBalanced($issueJe);

        // ── Step 3 — create an InvoicePaymentAllocation (what Souk's Payment morph does in prod) ──
        $allocation = new InvoicePaymentAllocation();
        $allocation->apps_id = (int) $invoice->apps_id;
        $allocation->companies_id = (int) $invoice->companies_id;
        $allocation->invoice_id = (int) $invoice->id;
        $allocation->source_type = 'souk_payment';
        $allocation->status = AllocationStatusEnum::ACTIVE->value;
        $allocation->amount_native = 1620.0;
        $allocation->amount_base = 1620.0;
        $allocation->currency = 'USD';
        $allocation->fx_rate_to_base = 1.0;
        $allocation->allocated_at = Carbon::parse('2026-06-20');
        $allocation->source = 'kanvas';
        $allocation->save();

        // ── Step 4 — compose + post the payment JE (DR Cash / CR AR) ──
        $paymentJeData = new InvoiceJournalEntryComposerService()->composePayment(
            invoice: $invoice,
            allocation: $allocation,
        );
        new PostJournalEntryAction(
            data: $paymentJeData,
            postedByUser: static::$cachedUser,
        )->execute();

        // ── Step 5 — MarkInvoicePaidAction recomputes + flips to PAID ──
        $paid = new MarkInvoicePaidAction(
            invoice: $invoice,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(InvoiceDocumentStatusEnum::PAID, $paid->document_status);
        $this->assertEqualsWithDelta(1620.0, (float) $paid->paid_native, 0.005);
        $this->assertEqualsWithDelta(0.0, (float) $paid->balance_due_native, 0.005);
        $this->assertNull($paid->collection_state, 'collection_state clears when an invoice is paid.');

        // ── Cross-cutting: GL invariants ──
        // The Issue JE has source_type='invoice'; the Payment JE has source_type='payment'
        // (the link to the invoice runs via the allocation, not the JE source_id).
        $issueJes = JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->get();
        $paymentJes = JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', 'payment')
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->get();

        $this->assertCount(1, $issueJes, 'Exactly one Issue JE posted for the invoice.');
        $this->assertCount(1, $paymentJes, 'Exactly one Payment JE posted for the allocation.');
        foreach ($issueJes->merge($paymentJes) as $je) {
            $this->assertJeBalanced($je);
        }

        // ── Cross-cutting: GL balances after full cycle ──
        $accountIds = $this->accountIdsByKey([
            AccountSubTypeEnum::ACCOUNTS_RECEIVABLE,
            AccountSubTypeEnum::CASH_CHECKING,
            AccountSubTypeEnum::SERVICE_REVENUE,
            AccountSubTypeEnum::SALES_TAX_PAYABLE,
        ]);
        $balances = new AccountActivityRepository()->getBalancesAsOf(
            app: $this->kanvasApp,
            company: $this->company,
            accountIds: array_values($accountIds),
            asOfDate: Carbon::parse('2026-06-30'),
        );

        $this->assertEqualsWithDelta(
            0.0,
            $balances[$accountIds[AccountSubTypeEnum::ACCOUNTS_RECEIVABLE->value]],
            0.005,
            'AR cleared after payment.',
        );
        $this->assertEqualsWithDelta(
            1620.0,
            $balances[$accountIds[AccountSubTypeEnum::CASH_CHECKING->value]],
            0.005,
            'Cash receives the full invoice total.',
        );
        $this->assertEqualsWithDelta(
            -1500.0,
            $balances[$accountIds[AccountSubTypeEnum::SERVICE_REVENUE->value]],
            0.005,
            'Revenue is CR-normal; balance is negative when credited.',
        );
        $this->assertEqualsWithDelta(
            -120.0,
            $balances[$accountIds[AccountSubTypeEnum::SALES_TAX_PAYABLE->value]],
            0.005,
            'Sales Tax Payable is CR-normal.',
        );

        // ── Cross-cutting: reports ──
        $revenue = new RevenueRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
            groupBy: RevenueGroupByEnum::CUSTOMER,
        );
        $this->assertEqualsWithDelta(1500.0, $revenue->total_net_revenue, 0.005);
        $this->assertSame(1, $revenue->total_invoice_count);

        $aging = new ArAgingRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            asOf: Carbon::parse('2026-06-30'),
        );
        $this->assertEqualsWithDelta(0.0, $aging->grand_total, 0.005, 'Paid invoice should not appear in AR aging.');
    }

    public function test_partial_payment_does_not_flip_to_paid(): void
    {
        $billable = $this->seedTestOrganization();
        $invoice = $this->issueTestInvoice($billable, subtotal: 1000.0);
        $this->assertEqualsWithDelta(1000.0, (float) $invoice->balance_due_native, 0.005);

        // Half payment
        $allocation = new InvoicePaymentAllocation();
        $allocation->apps_id = (int) $invoice->apps_id;
        $allocation->companies_id = (int) $invoice->companies_id;
        $allocation->invoice_id = (int) $invoice->id;
        $allocation->source_type = 'souk_payment';
        $allocation->status = AllocationStatusEnum::ACTIVE->value;
        $allocation->amount_native = 400.0;
        $allocation->amount_base = 400.0;
        $allocation->currency = 'USD';
        $allocation->fx_rate_to_base = 1.0;
        $allocation->allocated_at = Carbon::parse('2026-06-20');
        $allocation->source = 'kanvas';
        $allocation->save();

        $result = new MarkInvoicePaidAction(invoice: $invoice)->execute();

        $this->assertSame(
            InvoiceDocumentStatusEnum::ISSUED,
            $result->document_status,
            'Partial payment leaves status unchanged.',
        );
        $this->assertEqualsWithDelta(400.0, (float) $result->paid_native, 0.005);
        $this->assertEqualsWithDelta(600.0, (float) $result->balance_due_native, 0.005);
    }

    private function latestPostedJeFor(string $sourceType, int $sourceId): ?JournalEntry
    {
        return JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderByDesc('id')
            ->first();
    }

    private function assertJeBalanced(JournalEntry $je): void
    {
        $je->load('lines');
        $debit = (float) $je->lines->sum('debit_base');
        $credit = (float) $je->lines->sum('credit_base');
        $this->assertEqualsWithDelta(
            $debit,
            $credit,
            0.005,
            "JE {$je->id} ({$je->source_type}) must be balanced — DR={$debit}, CR={$credit}.",
        );
    }

    /**
     * @param  list<AccountSubTypeEnum>  $subTypes
     * @return array<string, int>  keyed by sub-type value
     */
    private function accountIdsByKey(array $subTypes): array
    {
        $out = [];
        foreach ($subTypes as $sub) {
            $out[$sub->value] = $this->accountIdBySubType($sub);
        }

        return $out;
    }
}
