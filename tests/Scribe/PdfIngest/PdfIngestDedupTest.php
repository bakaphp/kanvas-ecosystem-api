<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/**
 * Covers PR 9.1 dedup paths beyond the message-id idempotency that PR 9 already provides:
 *
 *   Path 2 — same PDF content arrives via different email message_id
 *            → ProcessAccountingPdfAction computes sha256, finds prior log with the same hash, marks
 *              the new log IGNORED_DUPLICATE + links it to the same entity. No second entity created.
 *
 *   Path 3 — vendor invoice arrives with a bill_number that already exists for this tenant
 *            → ProposeBillFromPdfAction returns the existing Bill rather than creating a duplicate.
 *              The orchestrator marks the log as ENTITY_CREATED pointing at the pre-existing bill.
 *
 * Path 1 (message-id idempotency) is covered by PdfIngestRoutingTest.
 */
class PdfIngestDedupTest extends ScribeTestCase
{
    public function test_path2_content_hash_dedup_expense_receipt(): void
    {
        $hasher = new FakePdfContentHasher();
        $sharedHash = hash('sha256', 'shared-coffee-receipt-bytes');

        // First arrival
        $pdf1 = $this->createFilesystemRow();
        $hasher->overrideForUuid($pdf1->uuid, $sharedHash);

        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
            confidence: 0.95,
            reasoning: 'Paid coffee receipt',
            extracted: [
                'vendor_name' => 'Cafe Bustelo',
                'issue_date' => '2026-06-15',
                'currency' => 'USD',
                'subtotal' => 9.50,
                'tax' => 0.86,
                'total' => 10.36,
                'payment_method_hint' => 'credit_card',
            ],
        ));

        $log1 = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf1,
                messageId: 'first-arrival-id',
                fromEmail: 'receipts@cafebustelo.test',
            ),
            user: static::$cachedUser,
            classifier: $classifier,
            hasher: $hasher,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log1->status);
        $this->assertSame('expense', $log1->linked_entity_type);
        $firstExpenseId = $log1->linked_entity_id;

        // Second arrival — DIFFERENT Filesystem row, DIFFERENT messageId, but SAME content hash.
        // Simulates "user forwarded the same email a second time" or "upstream re-delivered with new id".
        $pdf2 = $this->createFilesystemRow();
        $hasher->overrideForUuid($pdf2->uuid, $sharedHash);

        $log2 = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf2,
                messageId: 'second-arrival-id',                     // different message_id
                fromEmail: 'receipts@cafebustelo.test',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier(),                    // empty — would throw if called
            hasher: $hasher,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::IGNORED_DUPLICATE, $log2->status);
        $this->assertSame('expense', $log2->linked_entity_type);
        $this->assertSame((int) $firstExpenseId, (int) $log2->linked_entity_id);
        $this->assertStringContainsString('Duplicate of pdf_ingest_log', (string) $log2->rejected_reason);

        // Only ONE Expense row exists for this PDF — no duplicate entity.
        $expenseCount = Expense::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('source', 'parsed_pdf')
            ->count();
        $this->assertSame(1, $expenseCount, 'Content-hash dedup should prevent a second Expense.');
    }

    public function test_path2_failed_priors_do_not_dedup_a_retry(): void
    {
        // If the first attempt FAILED (classifier timeout etc.) we should NOT block a retry of the
        // same content — re-processing might succeed this time.
        $hasher = new FakePdfContentHasher();
        $sharedHash = hash('sha256', 'retry-pdf');

        $pdf1 = $this->createFilesystemRow();
        $hasher->overrideForUuid($pdf1->uuid, $sharedHash);

        $log1 = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf1,
                messageId: 'flaky-retry-1',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queueException(new \RuntimeException('flaky network')),
            hasher: $hasher,
        )->execute();
        $this->assertSame(PdfIngestStatusEnum::FAILED, $log1->status);

        // Retry — same hash, FAILED prior should NOT block re-processing
        $pdf2 = $this->createFilesystemRow();
        $hasher->overrideForUuid($pdf2->uuid, $sharedHash);

        $log2 = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf2,
                messageId: 'flaky-retry-2',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
                confidence: 0.9,
                extracted: [
                    'vendor_name' => 'Retry Vendor',
                    'currency' => 'USD',
                    'subtotal' => 30.0,
                    'tax' => 0.0,
                    'total' => 30.0,
                    'payment_method_hint' => 'credit_card',
                ],
            )),
            hasher: $hasher,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log2->status);
        $this->assertNotNull($log2->linked_entity_id);
    }

    public function test_path3_vendor_invoice_dedup_by_bill_number(): void
    {
        $hasher = new FakePdfContentHasher();

        // First arrival — creates a draft Bill with bill_number INV-2026-555
        $pdf1 = $this->createFilesystemRow();
        $log1 = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf1,
                messageId: 'aws-original',
                fromEmail: 'invoice@amazonaws.com',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.93,
                extracted: [
                    'vendor_name' => 'AWS',
                    'bill_number' => 'INV-2026-555',
                    'issue_date' => '2026-06-15',
                    'due_date' => '2026-07-15',
                    'currency' => 'USD',
                    'subtotal' => 5240.0,
                    'tax' => 0.0,
                    'total' => 5240.0,
                ],
            )),
            hasher: $hasher,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log1->status);
        $firstBillId = $log1->linked_entity_id;
        $this->assertNotNull($firstBillId);

        // Second arrival — DIFFERENT content hash (so Path 2 doesn't fire), same bill_number.
        // This simulates "vendor sent the invoice via email AND we uploaded the same one from a portal".
        $pdf2 = $this->createFilesystemRow();

        $log2 = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf2,
                messageId: 'aws-portal-upload',
                fromEmail: 'admin@example.test',                    // different sender path
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.92,
                extracted: [
                    'vendor_name' => 'AWS',
                    'bill_number' => 'INV-2026-555',                // SAME number as the first
                    'currency' => 'USD',
                    'subtotal' => 5240.0,
                    'tax' => 0.0,
                    'total' => 5240.0,
                ],
            )),
            hasher: $hasher,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log2->status);
        $this->assertSame('bill', $log2->linked_entity_type);
        $this->assertSame(
            (int) $firstBillId,
            (int) $log2->linked_entity_id,
            'Second log should link to the SAME Bill as the first — not create a duplicate.',
        );

        // Only ONE Bill exists for INV-2026-555
        $billCount = Bill::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('bill_number', 'INV-2026-555')
            ->count();
        $this->assertSame(1, $billCount, 'Bill-number dedup should prevent a second Bill row.');
    }

    public function test_path3_voided_bills_do_not_block_a_new_one_with_same_number(): void
    {
        // If a prior bill_number is VOIDED, we should NOT consider it a duplicate — the user
        // intentionally rejected the prior copy.
        $hasher = new FakePdfContentHasher();

        // First arrival — write a Bill directly so we can void it without needing receive
        $bill = new Bill();
        $bill->apps_id = $this->kanvasApp->getId();
        $bill->companies_id = $this->company->getId();
        $bill->bill_number = 'INV-VOIDED';
        $bill->document_status = BillDocumentStatusEnum::VOIDED;
        $bill->voided_at = Carbon::now();
        $bill->void_reason_code = 'wrong_company';
        $bill->currency = 'USD';
        $bill->fx_rate_to_base = 1.0;
        $bill->subtotal_native = 0.0;
        $bill->tax_native = 0.0;
        $bill->discount_native = 0.0;
        $bill->total_native = 100.0;
        $bill->paid_native = 0.0;
        $bill->balance_due_native = 0.0;
        $bill->subtotal_base = 0.0;
        $bill->tax_base = 0.0;
        $bill->discount_base = 0.0;
        $bill->total_base = 100.0;
        $bill->paid_base = 0.0;
        $bill->balance_due_base = 0.0;
        $bill->source = 'kanvas';
        $bill->origin = \Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum::KANVAS;
        $bill->save();

        // Now ingest a NEW PDF with the same bill_number — should NOT dedup against the voided one
        $pdf = $this->createFilesystemRow();
        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'fresh-invoice',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.9,
                extracted: [
                    'vendor_name' => 'Test Vendor',
                    'bill_number' => 'INV-VOIDED',
                    'currency' => 'USD',
                    'subtotal' => 200.0,
                    'tax' => 0.0,
                    'total' => 200.0,
                ],
            )),
            hasher: $hasher,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame('bill', $log->linked_entity_type);
        $this->assertNotSame((int) $bill->id, (int) $log->linked_entity_id);
    }

    public function test_multiple_attachments_of_one_email_each_produce_their_own_entity(): void
    {
        // Regression: a single email with 3 distinct PDF attachments shares ONE Message-Id but
        // stores 3 distinct Filesystem rows. Message-id idempotency must be scoped per-attachment
        // (filesystem_id) — otherwise the 2nd + 3rd short-circuit on the 1st's log and no entity is
        // created for them. Real-world symptom: "sent 3 files, system only saw 2".
        $hasher = new FakePdfContentHasher();
        $sharedMessageId = '<CA+OVWXhGPvM2cRQVhoUyhbUx7kTrzpib@mail.gmail.com>';

        $classifier = new FakePdfClassifier();
        foreach (['INV-0001', 'INV-0002', 'INV-0003'] as $billNumber) {
            $classifier->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.93,
                extracted: [
                    'vendor_name' => 'OpenAI',
                    'bill_number' => $billNumber,
                    'issue_date' => '2026-07-01',
                    'due_date' => '2026-07-31',
                    'currency' => 'USD',
                    'subtotal' => 100.0,
                    'tax' => 0.0,
                    'total' => 100.0,
                ],
            ));
        }

        $linkedBillIds = [];
        foreach (range(1, 3) as $i) {
            $log = new ProcessAccountingPdfAction(
                input: new PdfIngestInput(
                    app: $this->kanvasApp,
                    company: $this->company,
                    pdf: $this->createFilesystemRow(),
                    messageId: $sharedMessageId,
                    fromEmail: 'rivier@mctekk.com',
                    subject: 'Open ai facturas',
                ),
                user: static::$cachedUser,
                classifier: $classifier,
                hasher: $hasher,
            )->execute();

            $this->assertSame(
                PdfIngestStatusEnum::ENTITY_CREATED,
                $log->status,
                "Attachment #{$i} sharing the email's Message-Id must still create its own entity.",
            );
            $linkedBillIds[] = (int) $log->linked_entity_id;
        }

        $this->assertCount(3, array_unique($linkedBillIds), 'Each attachment should link to a distinct Bill.');

        $this->assertSame(
            3,
            PdfIngestLog::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('companies_id', $this->company->getId())
                ->where('message_id', $sharedMessageId)
                ->count(),
            'One email with 3 attachments must yield 3 log rows, not 1.',
        );
    }

    public function test_content_hash_is_stored_on_every_log_row(): void
    {
        // Sanity: orchestrator stamps content_sha256 on the log so future runs can dedup.
        $hasher = new FakePdfContentHasher();
        $pdf = $this->createFilesystemRow();

        new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'hash-stamp-test',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::UNKNOWN,
                confidence: 0.3,
                extracted: null,
            )),
            hasher: $hasher,
        )->execute();

        $row = PdfIngestLog::query()->where('message_id', 'hash-stamp-test')->first();
        $this->assertNotNull($row->content_sha256);
        $this->assertSame(hash('sha256', $pdf->uuid), $row->content_sha256);
    }
}
