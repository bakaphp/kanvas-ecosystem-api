<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Enums\PaymentStatusHintEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillPaymentAllocation;
use Kanvas\Scribe\Invoices\Enums\AllocationSourceTypeEnum;
use Kanvas\Scribe\Invoices\Enums\AllocationStatusEnum;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\Actions\ProposeBillFromPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/**
 * Covers the auto-advance path on PDF-ingested vendor invoices: when the LLM extracts
 * `payment_status_hint='paid'` AND classification confidence ≥ AUTO_ADVANCE_CONFIDENCE_THRESHOLD,
 * the orchestrator runs the full Bill lifecycle (DRAFT → RECEIVED → PAID) end-to-end:
 *
 *   - ReceiveBillAction posts the AP JE  (DR Expense / CR AP)
 *   - AllocateBillPaymentAction posts the Cash JE  (DR AP / CR Cash, default CASH_CHECKING)
 *   - MarkBillPaidAction recomputes paid_native/balance and flips status to PAID
 *
 * Below the threshold or without a paid hint the Bill stays DRAFT so the operator decides.
 *
 * Pair with PaymentStatusHintOnIngestedBillTest which covers the hint column propagation
 * (data-capture side) — this one is the GL-side outcome.
 */
class AutoAdvancePaidBillOnIngestTest extends ScribeTestCase
{
    public function test_high_confidence_paid_pdf_lands_as_paid_with_both_jes_posted(): void
    {
        $log = $this->ingestVendorInvoice(
            confidence: 0.93,
            extraExtractedFields: ['payment_status_hint' => 'paid'],
        );

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();

        $this->assertSame(
            BillDocumentStatusEnum::PAID,
            $bill->document_status,
            'High-confidence paid PDF must auto-advance Bill to PAID — that was the user-facing goal.',
        );
        $this->assertSame(PaymentStatusHintEnum::PAID, $bill->payment_status_hint);
        $this->assertEquals(100.0, (float) $bill->paid_native);
        $this->assertEquals(0.0, (float) $bill->balance_due_native);

        // Both JEs posted: Receive (DR Expense / CR AP) + Payment (DR AP / CR Cash)
        $receiveJe = JournalEntry::query()
            ->where('source_type', 'bill')
            ->where('source_id', $bill->id)
            ->firstOrFail();
        $this->assertNotNull($receiveJe, 'Receive JE must exist after auto-advance.');

        $paymentJe = JournalEntry::query()
            ->where('source_type', 'bill_payment')
            ->whereNull('is_reversal_of')
            ->orderByDesc('id')
            ->first();
        $this->assertNotNull(
            $paymentJe,
            'Payment JE must exist after auto-advance — without it the AP balance would never clear.',
        );

        // Active allocation row backs the Cash JE.
        $allocation = BillPaymentAllocation::query()
            ->where('bill_id', $bill->id)
            ->where('status', AllocationStatusEnum::ACTIVE)
            ->firstOrFail();
        $this->assertEquals(100.0, (float) $allocation->amount_native);
        $this->assertSame(AllocationSourceTypeEnum::MANUAL->value, $allocation->source_type);
        // Enum-constrained source column ('kanvas'|'adm_cloud'|'manual') — discriminator lives in metadata.
        $this->assertSame('manual', $allocation->source);
        $this->assertSame('pdf_ingest_auto', $allocation->metadata['origin'] ?? null);
    }

    public function test_low_confidence_paid_pdf_stays_draft(): void
    {
        $log = $this->ingestVendorInvoice(
            confidence: 0.50,
            extraExtractedFields: ['payment_status_hint' => 'paid'],
        );

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();

        $this->assertSame(
            BillDocumentStatusEnum::DRAFT,
            $bill->document_status,
            'Below threshold the Bill must stay DRAFT — the hint UI button is what advances it.',
        );
        // Hint column still captured so the UI can light up "Mark as paid?".
        $this->assertSame(PaymentStatusHintEnum::PAID, $bill->payment_status_hint);

        // No JE for an unreceived bill — the GL must not be touched.
        $this->assertSame(
            0,
            JournalEntry::query()->where('source_type', 'bill')->where('source_id', $bill->id)->count(),
        );
    }

    public function test_unpaid_hint_stays_draft_regardless_of_confidence(): void
    {
        $log = $this->ingestVendorInvoice(
            confidence: 0.97,
            extraExtractedFields: ['payment_status_hint' => 'unpaid'],
        );

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertSame(PaymentStatusHintEnum::UNPAID, $bill->payment_status_hint);
    }

    public function test_threshold_is_a_constant_so_change_is_visible_in_review(): void
    {
        // Sanity-pin the threshold so future tweaks to the cutoff aren't silent. Update both
        // the constant and this assertion together — the diff is the audit trail.
        $this->assertSame(0.85, ProposeBillFromPdfAction::AUTO_ADVANCE_CONFIDENCE_THRESHOLD);
    }

    /**
     * @param  array<string, mixed>  $extraExtractedFields
     */
    private function ingestVendorInvoice(float $confidence, array $extraExtractedFields)
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
            confidence: $confidence,
            reasoning: 'Vendor invoice for auto-advance test.',
            extracted: $extraExtractedFields + [
                'vendor_name' => 'AutoAdvanceVendor ' . uniqid(),
                'vendor_email' => 'billing-' . uniqid() . '@auto-advance.test',
                'bill_number' => 'AA-' . uniqid(),
                'currency' => 'USD',
                'subtotal' => 100.0,
                'tax' => 0.0,
                'total' => 100.0,
                'issue_date' => Carbon::today()->toDateString(),
            ],
        ));

        return new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'auto-advance-test-' . uniqid(),
            ),
            user: static::$cachedUser,
            classifier: $classifier,
            hasher: new FakePdfContentHasher(),
        )->execute();
    }
}
