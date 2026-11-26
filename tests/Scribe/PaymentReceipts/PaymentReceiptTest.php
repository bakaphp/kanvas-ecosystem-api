<?php

declare(strict_types=1);

namespace Tests\Scribe\PaymentReceipts;

use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Scribe\Invoices\Actions\AllocateInvoicePaymentAction;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\PaymentReceipts\Actions\CreatePaymentReceiptAction;
use Kanvas\Scribe\PaymentReceipts\DataTransferObject\PaymentReceipt as PaymentReceiptData;
use Kanvas\Scribe\PaymentReceipts\Enums\ConfigurationEnum;
use Kanvas\Scribe\PaymentReceipts\Models\PaymentReceipt;
use Kanvas\Scribe\PaymentReceipts\Services\PaymentReceiptPdfService;
use Kanvas\Scribe\Payments\Actions\CreateScribePaymentAction;
use Kanvas\Scribe\Payments\Enums\PaymentDirectionEnum;
use Kanvas\Scribe\Payments\Enums\PaymentMethodEnum;
use Kanvas\Scribe\Payments\Enums\PaymentStatusEnum;
use Kanvas\Scribe\Payments\Models\Payment;
use PHPUnit\Framework\Attributes\Group;
use Tests\Scribe\ScribeTestCase;

#[Group('serial')]
final class PaymentReceiptTest extends ScribeTestCase
{
    private mixed $originalReceivedBy = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Company settings write through Redis, which DatabaseTransactions never rolls back.
        $this->originalReceivedBy = $this->company->get(ConfigurationEnum::RECEIVED_BY->value);
    }

    protected function tearDown(): void
    {
        $this->originalReceivedBy === null
            ? $this->company->del(ConfigurationEnum::RECEIVED_BY->value)
            : $this->company->set(ConfigurationEnum::RECEIVED_BY->value, $this->originalReceivedBy);

        parent::tearDown();
    }

    public function test_one_receipt_covers_every_invoice_the_payment_paid(): void
    {
        $this->company->set(ConfigurationEnum::RECEIVED_BY->value, 'Test Receiver');
        $customer = $this->seedTestOrganization('Receipt Customer');
        $first = $this->issueTestInvoice($customer, 1000.0);
        $second = $this->issueTestInvoice($customer, 500.0);
        $payment = $this->clearedPayment(1500.0, $first, $second);

        $receipt = $this->issue($payment, payerBank: 'Test Bank', instrumentNumber: '4471');

        $this->assertSame('0000001', $receipt->receipt_number);
        $this->assertSame('Receipt Customer', $receipt->customer_name);
        $this->assertSame(1500.0, $receipt->amount);
        $this->assertSame('Test Receiver', $receipt->received_by);
        $this->assertSame('Test Bank', $receipt->payer_bank);
        $this->assertEquals(
            [$first->getId() => 1000.0, $second->getId() => 500.0],
            collect($receipt->invoices)->pluck('amount', 'invoice_id')->all()
        );
    }

    public function test_asking_twice_returns_the_same_receipt_and_spends_one_number(): void
    {
        $customer = $this->seedTestOrganization('Repeat Receipt Customer');
        $payment = $this->clearedPayment(300.0, $this->issueTestInvoice($customer, 300.0));
        $other = $this->clearedPayment(100.0, $this->issueTestInvoice($customer, 100.0));

        $first = $this->issue($payment);
        $again = $this->issue($payment);
        $next = $this->issue($other);

        $this->assertSame($first->getId(), $again->getId());
        $this->assertSame('0000001', $first->receipt_number);
        $this->assertSame('0000002', $next->receipt_number);
    }

    public function test_a_payment_that_is_not_confirmed_gets_no_receipt(): void
    {
        $customer = $this->seedTestOrganization('Pending Receipt Customer');
        $payment = $this->clearedPayment(200.0, $this->issueTestInvoice($customer, 200.0));
        $payment->status = PaymentStatusEnum::PENDING;
        $payment->save();

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('once the payment is confirmed');

        $this->issue($payment);
    }

    public function test_a_payment_reversed_after_it_was_loaded_gets_no_receipt(): void
    {
        $customer = $this->seedTestOrganization('Reversed Receipt Customer');
        $payment = $this->clearedPayment(200.0, $this->issueTestInvoice($customer, 200.0));
        Payment::query()->whereKey($payment->getId())->update(['status' => PaymentStatusEnum::REVERSED->value]);

        $this->assertSame(PaymentStatusEnum::CLEARED, $payment->status, 'the copy in hand still says cleared');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('is reversed');

        $this->issue($payment);
    }

    public function test_a_payment_we_made_gets_no_receipt(): void
    {
        $payment = $this->newPayment(50.0, PaymentDirectionEnum::OUTBOUND);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not for a payment we made');

        $this->issue($payment);
    }

    public function test_a_payment_applied_to_no_invoice_gets_no_receipt(): void
    {
        $payment = $this->newPayment(50.0, PaymentDirectionEnum::INBOUND);

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('not applied to any invoice');

        $this->issue($payment);
    }

    public function test_the_receipt_prints_in_spanish_with_the_amount_in_words(): void
    {
        $customer = $this->seedTestOrganization('Printed Receipt Customer');
        $first = $this->issueTestInvoice($customer, 1000.0);
        $second = $this->issueTestInvoice($customer, 250.5);
        $payment = $this->clearedPayment(1250.5, $first, $second);
        $receipt = $this->issue(
            $payment,
            payerBank: 'Test Bank',
            instrumentNumber: '4471',
            instrumentDate: Carbon::parse('2026-11-20')
        );

        $html = new PaymentReceiptPdfService($receipt)->render();

        $this->assertStringContainsString('Recibo de pago # 0000001', $html);
        $this->assertStringContainsString('Printed Receipt Customer', $html);
        $this->assertStringContainsString('US$1,250.50XXXXX', $html);
        $this->assertStringContainsString('mil doscientos cincuenta con 50/100', $html);
        $this->assertStringContainsString("Pago facts. No. {$first->invoice_number}, {$second->invoice_number}", $html);
        $this->assertStringContainsString('Transferencia', $html);
        $this->assertStringContainsString('20/11/2026', $html);
        $this->assertStringContainsString('Test Bank', $html);
    }

    public function test_a_peso_receipt_is_labelled_rd(): void
    {
        $customer = $this->seedTestOrganization('Peso Receipt Customer');
        $receipt = $this->issue($this->clearedPayment(100.0, $this->issueTestInvoice($customer, 100.0)));
        $receipt->currency = 'DOP';
        $receipt->amount = 2500.0;
        $receipt->save();

        $html = new PaymentReceiptPdfService($receipt)->render();

        $this->assertStringContainsString('RD$2,500.00XXXXX', $html);
        $this->assertStringContainsString('dos mil quinientos con 00/100', $html);
    }

    private function issue(
        Payment $payment,
        ?string $payerBank = null,
        ?string $instrumentNumber = null,
        ?Carbon $instrumentDate = null,
    ): PaymentReceipt {
        return new CreatePaymentReceiptAction(
            new PaymentReceiptData(
                app: $this->kanvasApp,
                company: $this->company,
                user: static::$cachedUser,
                payment: $payment,
                payerBank: $payerBank,
                instrumentNumber: $instrumentNumber,
                instrumentDate: $instrumentDate,
            )
        )->execute();
    }

    private function newPayment(float $amount, PaymentDirectionEnum $direction): Payment
    {
        return new CreateScribePaymentAction(
            app: $this->kanvasApp,
            company: $this->company,
            amountNative: $amount,
            currency: 'USD',
            direction: $direction,
            method: PaymentMethodEnum::WIRE,
            user: static::$cachedUser,
            reference: 'TEST-REF',
        )->execute();
    }

    /** One cleared payment spread over the invoices, each paid in full. */
    private function clearedPayment(float $amount, Invoice ...$invoices): Payment
    {
        $payment = $this->newPayment($amount, PaymentDirectionEnum::INBOUND);

        foreach ($invoices as $invoice) {
            new AllocateInvoicePaymentAction(
                invoice: $invoice,
                amountNative: (float) $invoice->total_native,
                method: PaymentMethodEnum::WIRE,
                payment: $payment,
                user: static::$cachedUser,
            )->execute();
        }

        return $payment;
    }
}
