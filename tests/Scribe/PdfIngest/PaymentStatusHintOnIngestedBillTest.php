<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Kanvas\Scribe\Bills\Enums\PaymentStatusHintEnum;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\PdfIngest\Actions\ProcessAccountingPdfAction;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfIngestInput;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\Scribe\PdfIngest\Stubs\FakePdfContentHasher;
use Tests\Scribe\ScribeTestCase;

/**
 * Covers `payment_status_hint` propagation from LLM extraction → Bill column.
 *
 *   - LLM returns 'paid'    → Bill.payment_status_hint = PAID
 *   - LLM returns 'unpaid'  → Bill.payment_status_hint = UNPAID
 *   - LLM omits / returns garbage → Bill.payment_status_hint = UNKNOWN (default)
 *
 * GL-side outcome (auto-advance from PAID hint when confidence ≥ threshold) is covered in
 * AutoAdvancePaidBillOnIngestTest. To keep these tests focused on the column value, they pin
 * confidence BELOW the auto-advance threshold so the Bill stays DRAFT regardless of the hint.
 */
class PaymentStatusHintOnIngestedBillTest extends ScribeTestCase
{
    public function test_paid_hint_sets_column_on_draft_bill(): void
    {
        $log = $this->ingestVendorInvoice(['payment_status_hint' => 'paid']);

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();

        $this->assertSame(
            PaymentStatusHintEnum::PAID,
            $bill->payment_status_hint,
            "PDF says 'paid' → Bill draft carries the hint for operator UI.",
        );
        // Lifecycle untouched — hint is data-capture only.
        $this->assertSame('draft', $bill->document_status->value);
    }

    public function test_unpaid_hint_sets_column_on_draft_bill(): void
    {
        $log = $this->ingestVendorInvoice(['payment_status_hint' => 'unpaid']);

        /** @var Bill $bill */
        $bill = Bill::query()->where('id', $log->linked_entity_id)->firstOrFail();
        $this->assertSame(PaymentStatusHintEnum::UNPAID, $bill->payment_status_hint);
        $this->assertSame('draft', $bill->document_status->value);
    }

    public function test_missing_or_invalid_hint_defaults_to_unknown(): void
    {
        // LLM omits the field entirely
        $omitted = $this->ingestVendorInvoice([]);
        $omittedBill = Bill::query()->where('id', $omitted->linked_entity_id)->firstOrFail();
        $this->assertSame(PaymentStatusHintEnum::UNKNOWN, $omittedBill->payment_status_hint);

        // LLM emits an unrecognized value — defensive fallback to UNKNOWN
        $garbage = $this->ingestVendorInvoice(['payment_status_hint' => 'maybe_kinda_paid']);
        $garbageBill = Bill::query()->where('id', $garbage->linked_entity_id)->firstOrFail();
        $this->assertSame(PaymentStatusHintEnum::UNKNOWN, $garbageBill->payment_status_hint);
    }

    /**
     * @param  array<string, mixed>  $extraExtractedFields
     */
    private function ingestVendorInvoice(array $extraExtractedFields)
    {
        $pdf = $this->createFilesystemRow();
        $classifier = new FakePdfClassifier()->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
            // Sub-threshold so auto-advance does NOT fire — these tests cover only the column value.
            confidence: 0.50,
            reasoning: 'Vendor invoice for unit test.',
            extracted: $extraExtractedFields + [
                'vendor_name' => 'TestVendor ' . uniqid(),
                'vendor_email' => 'billing-' . uniqid() . '@vendor.test',
                'bill_number' => 'BN-' . uniqid(),
                'currency' => 'USD',
                'subtotal' => 100.0,
                'tax' => 0.0,
                'total' => 100.0,
            ],
        ));

        return new ProcessAccountingPdfAction(
            input: new PdfIngestInput(
                app: $this->kanvasApp,
                company: $this->company,
                pdf: $pdf,
                messageId: 'payment-hint-test-' . uniqid(),
            ),
            user: static::$cachedUser,
            classifier: $classifier,
            hasher: new FakePdfContentHasher(),
        )->execute();
    }
}
