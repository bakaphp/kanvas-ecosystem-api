<?php

declare(strict_types=1);

namespace Tests\Scribe\PaymentReceipts;

use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ApplyArPaymentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GeneratePaymentReceiptTool;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\PaymentReceipts\Models\PaymentReceipt;
use Kanvas\Scribe\PaymentReceipts\Services\PaymentReceiptPdfService;
use Kanvas\Scribe\Payments\Enums\PaymentStatusEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use Tests\Scribe\ScribeTestCase;
use Tests\Traits\AssertsToolRunKeys;

final class GeneratePaymentReceiptToolTest extends ScribeTestCase
{
    use AssertsToolRunKeys;

    public function test_a_payment_the_agent_just_applied_gets_its_receipt_as_a_pdf(): void
    {
        $this->requirePdfBinary();

        [$invoice, $paymentId] = $this->appliedPayment('Tool Receipt Customer', 800.0);

        $result = $this->tool()->__invoke(
            payment_id: $paymentId,
            payer_bank: 'Test Bank',
            instrument_number: '9001',
            instrument_date: '2026-11-20',
        );

        $this->assertTrue($result['generated']);
        $this->assertSame('0000001', $result['receipt_number']);
        $this->assertSame([$invoice->invoice_number], $result['invoices']);
        $this->assertNotEmpty($result['file_url']);

        $receipt = PaymentReceipt::query()->findOrFail($result['receipt_id']);
        $this->assertSame('Test Bank', $receipt->payer_bank);
        $this->assertNotNull($receipt->getFileByName(PaymentReceiptPdfService::FIELD_NAME));
    }

    public function test_asking_again_returns_the_same_receipt_and_file(): void
    {
        $this->requirePdfBinary();

        [, $paymentId] = $this->appliedPayment('Repeat Tool Customer', 120.0);

        $first = $this->tool()->__invoke(payment_id: $paymentId);
        $again = $this->tool()->__invoke(payment_id: $paymentId);

        $this->assertSame($first['receipt_id'], $again['receipt_id']);
        $this->assertSame($first['filesystem_id'], $again['filesystem_id']);
    }

    public function test_a_payment_that_is_not_confirmed_is_refused_with_the_reason(): void
    {
        [, $paymentId] = $this->appliedPayment('Unconfirmed Tool Customer', 90.0);
        Payment::query()->whereKey($paymentId)->update(['status' => PaymentStatusEnum::PENDING->value]);

        $result = $this->tool()->__invoke(payment_id: $paymentId);

        $this->assertFalse($result['generated']);
        $this->assertSame('not_receivable', $result['reason']);
        $this->assertStringContainsString('confirmed', $result['message']);
    }

    public function test_a_date_that_is_not_a_date_is_asked_for_again_and_issues_nothing(): void
    {
        [, $paymentId] = $this->appliedPayment('Bad Date Tool Customer', 75.0);

        $result = $this->tool()->__invoke(payment_id: $paymentId, instrument_date: 'the day it cleared');

        $this->assertFalse($result['generated']);
        $this->assertSame('invalid_instrument_date', $result['reason']);
        $this->assertFalse(PaymentReceipt::query()->where('payment_id', $paymentId)->exists());
    }

    public function test_an_unknown_payment_is_reported_not_guessed(): void
    {
        $result = $this->tool()->__invoke(payment_id: 999999999);

        $this->assertFalse($result['generated']);
        $this->assertSame('payment_not_found', $result['reason']);
    }

    public function test_the_receipt_tool_keys_its_run_budget_by_inputs(): void
    {
        $this->assertRunKeyFollowsInputs(new GeneratePaymentReceiptTool());
    }

    private function requirePdfBinary(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }
    }

    private function tool(): GeneratePaymentReceiptTool
    {
        return new GeneratePaymentReceiptTool()->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    /**
     * @return array{Invoice, int}
     */
    private function appliedPayment(string $customerName, float $amount): array
    {
        $invoice = $this->issueTestInvoice($this->seedTestOrganization($customerName), $amount);

        $applied = new ApplyArPaymentTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: $invoice->getId(), amount: $amount, reference: 'TEST-REF', method: 'wire');

        return [$invoice, (int) $applied['payment_id']];
    }
}
