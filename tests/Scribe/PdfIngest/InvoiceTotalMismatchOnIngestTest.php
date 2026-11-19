<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Kanvas\Scribe\Bills\Enums\BillDocumentStatusEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestStatusEnum;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/** An emailed invoice whose lines do not add up to the total printed on it must not reach an approver as is. */
class InvoiceTotalMismatchOnIngestTest extends ScribeTestCase
{
    public function test_lines_that_do_not_add_up_to_the_printed_total_hold_the_bill_for_review(): void
    {
        $log = $this->ingest('TOT-001', [
            'subtotal' => 630.0,
            'tax' => 0.0,
            'total' => 700.0,
            'line_items' => [
                ['description' => 'Office chairs', 'qty' => 4, 'unit_price' => 120.0, 'line_total' => 480.0],
                ['description' => 'Desk lamps', 'qty' => 10, 'unit_price' => 15.0, 'line_total' => 150.0],
            ],
        ]);

        $bill = Bill::query()->findOrFail($log->linked_entity_id);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertSame(BillDocumentStatusEnum::DRAFT, $bill->document_status);
        $this->assertStringContainsString('The lines add up to 630.00 but the invoice total is 700.00.', (string) $log->rejected_reason);
    }

    public function test_a_zero_subtotal_with_a_printed_total_holds_the_bill_for_review(): void
    {
        $log = $this->ingest('TOT-002', [
            'subtotal' => 0.0,
            'tax' => 0.0,
            'total' => 630.0,
        ]);

        $this->assertSame(PdfIngestStatusEnum::NEEDS_REVIEW, $log->status);
        $this->assertStringContainsString('The lines add up to 0.00 but the invoice total is 630.00.', (string) $log->rejected_reason);
    }

    public function test_lines_that_add_up_to_the_printed_total_are_not_flagged(): void
    {
        $log = $this->ingest('TOT-003', [
            'subtotal' => 630.0,
            'tax' => 0.0,
            'total' => 630.0,
            'line_items' => [
                ['description' => 'Office chairs', 'qty' => 4, 'unit_price' => 120.0, 'line_total' => 480.0],
                ['description' => 'Desk lamps', 'qty' => 10, 'unit_price' => 15.0, 'line_total' => 150.0],
            ],
        ]);

        $this->assertStringNotContainsString('The lines add up to', (string) $log->rejected_reason);
    }

    public function test_a_line_given_only_its_line_total_keeps_that_amount(): void
    {
        $log = $this->ingest('TOT-004', [
            'subtotal' => 100.0,
            'tax' => 0.0,
            'total' => 100.0,
            'line_items' => [['description' => 'Cloud hosting', 'line_total' => 100.0]],
        ]);

        $bill = Bill::query()->findOrFail($log->linked_entity_id);

        $this->assertSame(100.0, (float) $bill->total_native);
        $this->assertStringNotContainsString('The lines add up to', (string) $log->rejected_reason);
    }

    /**
     * @param array<string, mixed> $figures
     */
    private function ingest(string $billNumber, array $figures): PdfIngestLog
    {
        return new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $this->createFilesystemRow(),
                messageId: 'total-check-' . $billNumber,
                fromEmail: 'billing@totalcheck.test',
            ),
            user: static::$cachedUser,
            classifier: new FakePdfClassifier()->queue(new PdfClassificationResult(
                document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
                confidence: 0.93,
                extracted: [
                    'vendor_name' => 'Total Check Supplies LLC',
                    'bill_number' => $billNumber,
                    'currency' => 'USD',
                    ...$figures,
                ],
            )),
            hasher: new FakePdfContentHasher(),
        )->execute();
    }
}
