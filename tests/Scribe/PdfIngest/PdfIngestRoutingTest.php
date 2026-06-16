<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\Expenses\Enums\ExpensePaidByEnum;
use Kanvas\Scribe\Expenses\Enums\ExpenseStatusEnum;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Expenses\Models\ExpenseReceipt;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use RuntimeException;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\TestCase;

/**
 * Covers ProcessAccountingPdfAction's routing matrix + ProposeExpenseFromPdfAction's draft expense write.
 *
 * Each routing branch gets a dedicated test — proves the LLM verdict drives the right downstream behavior
 * and the pdf_ingest_log captures the right status. The FakePdfClassifier swaps in for the live Gemini
 * binding so tests stay offline.
 */
class PdfIngestRoutingTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    public function test_expense_receipt_creates_draft_expense_and_attaches_pdf(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
            confidence: 0.94,
            reasoning: 'Shows "PAID" stamp and last-4 card digits — classic credit-card receipt.',
            extracted: [
                'vendor_name' => 'Mercury Coffee',
                'vendor_tax_id' => null,
                'vendor_email' => 'orders@mercurycoffee.test',
                'issue_date' => '2026-06-15',
                'currency' => 'USD',
                'subtotal' => 18.50,
                'tax' => 1.61,
                'total' => 20.11,
                'line_items' => [
                    ['description' => 'Espresso x4', 'qty' => 4, 'unit_price' => 4.625, 'line_total' => 18.50],
                ],
                'tax_metadata' => null,
                'payment_method_hint' => 'credit_card',
                'notes' => 'Team coffee meeting Friday',
            ],
        ));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'mailgun-msg-001@example.test',
                fromEmail: 'orders@mercurycoffee.test',
                subject: 'Your receipt',
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame(PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT, $log->document_type);
        $this->assertSame('expense', $log->linked_entity_type);
        $this->assertNotNull($log->linked_entity_id);
        $this->assertGreaterThan(0.9, $log->confidence);

        /** @var Expense $expense */
        $expense = Expense::query()->where('id', $log->linked_entity_id)->first();
        $this->assertNotNull($expense);
        $this->assertSame(ExpenseStatusEnum::DRAFT, $expense->status);
        $this->assertSame('parsed_pdf', $expense->source);
        $this->assertEquals(20.11, (float) $expense->total_native);
        $this->assertSame(ExpensePaidByEnum::COMPANY_CARD, $expense->paid_by);
        $this->assertSame('Mercury Coffee', $expense->vendor_display_name);

        $receipt = ExpenseReceipt::query()->where('expense_id', $expense->id)->first();
        $this->assertNotNull($receipt, 'The PDF should be attached as an ExpenseReceipt.');
        $this->assertSame((int) $pdf->getKey(), (int) $receipt->filesystem_id);

        // Metadata captures the raw LLM payload for audit / re-classification
        $ingest = $expense->metadata['ingest'] ?? null;
        $this->assertNotNull($ingest);
        $this->assertSame('parsed_pdf', $ingest['source']);
        $this->assertSame('Mercury Coffee', $ingest['vendor_name']);
    }

    public function test_vendor_invoice_creates_draft_bill(): void
    {
        // PR 10 promoted vendor-invoice routing from "log only" to "draft Bill creation".
        // The Bill lands in DRAFT for operator review (vendor resolution + receive happen there).
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
            confidence: 0.91,
            reasoning: 'Invoice from AWS dated 2026-06-15, due 2026-07-15, balance due > 0.',
            extracted: [
                'vendor_name' => 'Amazon Web Services',
                'issue_date' => '2026-06-15',
                'due_date' => '2026-07-15',
                'currency' => 'USD',
                'subtotal' => 5240.00,
                'tax' => 0.0,
                'total' => 5240.00,
            ],
        ));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'aws-invoice-12345',
                fromEmail: 'no-reply@amazonaws.com',
                subject: 'Your AWS Invoice',
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::ENTITY_CREATED, $log->status);
        $this->assertSame(PdfIngestDocumentTypeEnum::VENDOR_INVOICE, $log->document_type);
        $this->assertSame('bill', $log->linked_entity_type);
        $this->assertNotNull($log->linked_entity_id);

        $bill = \Kanvas\Scribe\Bills\Models\Bill::query()->where('id', $log->linked_entity_id)->first();
        $this->assertNotNull($bill);
        $this->assertSame(\Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertSame('parsed_pdf', $bill->source);
        $this->assertEquals(5240.00, (float) $bill->total_native);
        $this->assertSame('Amazon Web Services', $bill->vendor_display_name);
        $this->assertSame((int) $log->id, (int) $bill->pdf_ingest_log_id);
    }

    public function test_vendor_quote_logs_quote_inbound_only(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::VENDOR_QUOTE,
            confidence: 0.88,
            reasoning: 'Proforma quote from a vendor — not yet an invoice.',
            extracted: ['vendor_name' => 'DataDog', 'currency' => 'USD', 'total' => 12_000.0],
        ));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::QUOTE_INBOUND_LOGGED, $log->status);
        $this->assertNull($log->linked_entity_type);
    }

    public function test_our_invoice_ignored(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::OUR_INVOICE,
            confidence: 0.97,
            reasoning: 'Letterhead matches our company — forwarded back to us.',
            extracted: ['invoice_number' => 'INV-2026-001'],
        ));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::IGNORED_OUR_DOC, $log->status);
        $this->assertNull($log->linked_entity_type);
    }

    public function test_unknown_document_rejected(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::UNKNOWN,
            confidence: 0.42,
            reasoning: 'PDF appears to be a contract, not an accounting document.',
            extracted: null,
        ));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::REJECTED_UNKNOWN, $log->status);
        $this->assertNotNull($log->rejected_reason);
    }

    public function test_classifier_failure_logs_failed_status_without_throwing(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queueException(new RuntimeException('Gemini timeout'));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::FAILED, $log->status);
        $this->assertStringContainsString('Gemini timeout', (string) $log->rejected_reason);
    }

    public function test_idempotent_on_message_id(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
            confidence: 0.95,
            reasoning: 'First call',
            extracted: [
                'vendor_name' => 'Test Vendor',
                'issue_date' => '2026-06-15',
                'currency' => 'USD',
                'subtotal' => 50.00,
                'tax' => 0.0,
                'total' => 50.00,
                'payment_method_hint' => 'credit_card',
            ],
        ));

        $first = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'duplicate-msg-id',
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        // Second call uses the SAME messageId — should short-circuit on the existing log row
        $second = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'duplicate-msg-id',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier(),     // empty queue — would throw if called
        )->execute();

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(
            1,
            PdfIngestLog::query()
                ->where('message_id', 'duplicate-msg-id')
                ->count(),
            'Same message_id must not create a second log row.',
        );
    }

    public function test_expense_skipped_when_total_missing(): void
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT,
            confidence: 0.5,
            reasoning: 'Receipt but total field was blank',
            extracted: ['vendor_name' => 'Garbled receipt', 'currency' => 'USD'],
        ));

        $log = new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
            ),
            user: static::$cachedUser,
            classifier: $classifier,
        )->execute();

        $this->assertSame(PdfIngestStatusEnum::FAILED, $log->status);
        $this->assertStringContainsString(
            'no usable `total`',
            (string) $log->rejected_reason,
            'rejected_reason must say "no usable total" so the operator knows the LLM extraction was the problem.',
        );
        $this->assertSame(
            0,
            Expense::query()
                ->where('apps_id', $this->kanvasApp->getId())
                ->where('companies_id', $this->company->getId())
                ->where('source', 'parsed_pdf')
                ->count(),
        );
    }

    private function createFilesystemRow(): Filesystem
    {
        $filesystem = new Filesystem();
        $filesystem->apps_id = $this->kanvasApp->getId();
        $filesystem->companies_id = $this->company->getId();
        $filesystem->users_id = static::$cachedUser->getId();
        $filesystem->name = 'invoice-' . Carbon::now()->format('YmdHis') . '.pdf';
        $filesystem->path = 'inbound/' . $filesystem->name;
        $filesystem->url = 'https://example.test/' . $filesystem->path;
        $filesystem->size = '12345';
        $filesystem->file_type = 'pdf';
        $filesystem->save();

        return $filesystem;
    }
}
