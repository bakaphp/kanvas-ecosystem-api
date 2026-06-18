<?php

declare(strict_types=1);

namespace Tests\Scribe\E2E;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Expenses\Actions\ApproveExpenseAction;
use Kanvas\Scribe\Expenses\Actions\SubmitExpenseForApprovalAction;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseReceipt;
use Kanvas\Scribe\Ledger\Enums\AccountSubTypeEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\Reports\Repositories\AccountActivityRepository;
use Kanvas\Scribe\Reports\Repositories\ProfitAndLossRepository;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/**
 * Flow 3 — Full PDF-to-Expense-to-Approval pipeline.
 *
 *   1. ProcessAccountingPdfAction with EXPENSE_RECEIPT classification
 *   2. → ProposeExpenseFromPdfAction creates draft Expense + attaches PDF as receipt
 *   3. → SubmitExpenseForApprovalAction
 *   4. → ApproveExpenseAction posts JE → expense lands on P&L
 *
 * This is the operator's "AP magic" path — a vendor receipt hits Mailgun, lands as a draft Expense,
 * the operator clicks approve, the JE posts. End-to-end smoke test that the PdfIngest → Scribe →
 * GL pipeline doesn't break at any join.
 */
class PdfToExpenseFlowTest extends ScribeTestCase
{
    public function test_pdf_receipt_lands_as_draft_then_approves_into_pnl(): void
    {
        $pdf = $this->createFilesystemRow();

        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
            confidence: 0.95,
            reasoning: 'Hotel receipt, paid by company card. Total $250.',
            extracted: [
                'vendor_name' => 'Hotel Sol',
                'vendor_email' => 'reception@hotelsol.test',
                'issue_date' => '2026-06-12',
                'currency' => 'USD',
                'subtotal' => 230.0,
                'tax' => 20.0,
                'total' => 250.0,
                'line_items' => [
                    ['description' => 'Room — 2 nights', 'qty' => 2, 'unit_price' => 115.0, 'line_total' => 230.0],
                ],
                'payment_method_hint' => 'credit_card',
                'notes' => 'Sales conference Q2',
            ],
        ));

        // ── Step 1+2 — ingest the PDF, draft expense lands ──
        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'mailgun-hotel-q2',
                fromEmail: 'reception@hotelsol.test',
                subject: 'Your stay receipt',
            ),
            user: static::$cachedUser,
            classifier: $classifier,
            hasher: new FakePdfContentHasher(),
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame('expense', $log->linked_entity_type);
        $this->assertNotNull($log->linked_entity_id);
        $this->assertNotNull($log->content_sha256, 'Hash stamped for future dedup.');

        /** @var Expense $expense */
        $expense = Expense::query()->where('id', $log->linked_entity_id)->firstOrFail();
        $this->assertSame(ExpenseStatusEnum::DRAFT, $expense->status);
        $this->assertSame('parsed_pdf', $expense->source);
        $this->assertSame($pdf->uuid, $expense->external_id);
        $this->assertEqualsWithDelta(250.0, (float) $expense->total_native, 0.005);
        $this->assertSame(ExpensePaidByEnum::COMPANY_CARD, $expense->paid_by);
        $this->assertSame('Hotel Sol', $expense->vendor_display_name);

        // PDF attached as a receipt
        $receipt = ExpenseReceipt::query()->where('expense_id', $expense->id)->first();
        $this->assertNotNull($receipt);
        $this->assertSame((int) $pdf->getKey(), (int) $receipt->filesystem_id);

        // Extracted payload preserved verbatim in metadata.ingest
        $this->assertSame('Hotel Sol', $expense->metadata['ingest']['vendor_name'] ?? null);

        // No JE yet — drafts don't post.
        $this->assertCount(0, $this->postedJesFor('expense', (int) $expense->id));

        // ── Step 3+4 — operator approves → JE posts ──
        $submitted = new SubmitExpenseForApprovalAction(
            expense: $expense,
            user: static::$cachedUser,
        )->execute();
        $approved = new ApproveExpenseAction(
            expense: $submitted,
            approver: static::$cachedUser,
        )->execute();

        $this->assertSame(ExpenseStatusEnum::APPROVED, $approved->status);
        $jes = $this->postedJesFor('expense', (int) $approved->id);
        $this->assertCount(1, $jes, 'One approval JE posted.');
        $this->assertJeBalanced($jes->first());

        // ── Cross-cutting: P&L picks up the parsed-PDF expense ──
        $pnl = new ProfitAndLossRepository()->generate(
            app: $this->kanvasApp,
            company: $this->company,
            periodStart: Carbon::parse('2026-06-01'),
            periodEnd: Carbon::parse('2026-06-30'),
        );
        $this->assertEqualsWithDelta(
            250.0,
            $pnl->operating_expenses->total,
            0.005,
            'PDF-sourced expense flows through to P&L exactly like a manually entered one.',
        );

        // ── Cross-cutting: GL balances ──
        $travelId = $this->accountIdBySubType(AccountSubTypeEnum::TRAVEL_AND_MEALS);
        $balances = new AccountActivityRepository()->getBalancesAsOf(
            app: $this->kanvasApp,
            company: $this->company,
            accountIds: [$travelId],
            asOfDate: Carbon::parse('2026-06-30'),
        );
        $this->assertEqualsWithDelta(250.0, $balances[$travelId], 0.005);
    }

    public function test_unusable_pdf_does_not_create_an_expense(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
            confidence: 0.55,
            reasoning: 'Blurry receipt, total unreadable.',
            extracted: [
                'vendor_name' => 'Unreadable',
                'currency' => 'USD',
                // total missing → ProposeExpenseFromPdfAction returns null
            ],
        ));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'blurry-receipt',
            ),
            user: static::$cachedUser,
            classifier: $classifier,
            hasher: new FakePdfContentHasher(),
        )->execute();

        $this->assertNull($log->linked_entity_id, 'Unusable extraction does not create an expense.');
        $expenseCount = Expense::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source', 'parsed_pdf')
            ->count();
        $this->assertSame(0, $expenseCount);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, JournalEntry>
     */
    private function postedJesFor(string $sourceType, int $sourceId)
    {
        return JournalEntry::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', JournalEntryStatusEnum::POSTED->value)
            ->whereNull('is_reversal_of')
            ->orderBy('id')
            ->get();
    }

    private function assertJeBalanced(JournalEntry $je): void
    {
        $je->load('lines');
        $debit = (float) $je->lines->sum('debit_base');
        $credit = (float) $je->lines->sum('credit_base');
        $this->assertEqualsWithDelta($debit, $credit, 0.005, "JE {$je->id} not balanced.");
    }
}
