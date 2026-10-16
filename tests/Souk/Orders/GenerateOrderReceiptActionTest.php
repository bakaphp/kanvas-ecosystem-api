<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Support\Collection;
use Kanvas\Connectors\Movipass\Enums\OrderTypeEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Actions\GenerateOrderReceiptAction;
use Kanvas\Souk\Orders\DataTransferObject\OrderReceipt;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Orders\Models\OrderTypes;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class GenerateOrderReceiptActionTest extends TestCase
{
    public function testResolverUsesSnapshotColumnsWhenPresent(): void
    {
        $order = $this->makeOrderWithMetadata([
            'payment_date' => '2026-04-17 10:30 AM',
            'release_date' => '2026-04-17 11:45 AM',
        ]);

        $payment = new Payments([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method' => 'card',
            'payment_method_brand' => 'VISA',
            'payment_method_last_four' => '1234',
            'metadata' => ['data' => ['payment_response' => ['processorInformation' => ['transactionId' => 'TX-001']]]],
        ]);

        $this->attachPayments($order, [$payment]);

        $result = $this->makeAction($order)->receiptData();

        $this->assertSame('VISA 1234', $result['paymentType']);
        $this->assertSame('2026-04-17 10:30 AM', $result['paymentDate']);
        $this->assertSame('2026-04-17 11:45 AM', $result['releaseDate']);
        $this->assertSame('TX-001', $result['transactionNumber']);
    }

    public function testResolverFallsBackToRelatedPaymentMethod(): void
    {
        $order = $this->makeOrderWithMetadata([]);

        $payment = new Payments([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method' => 'card',
        ]);

        $method = new PaymentMethods([
            'payment_methods_brand' => 'MASTERCARD',
            'payment_ending_numbers' => '5678',
        ]);
        $payment->setRelation('paymentMethod', $method);

        $this->attachPayments($order, [$payment]);

        $result = $this->makeAction($order)->receiptData();

        $this->assertSame('MASTERCARD 5678', $result['paymentType']);
    }

    public function testResolverFallsBackToEnrollmentMetadataBrand(): void
    {
        $order = $this->makeOrderWithMetadata([]);

        $payment = new Payments([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method' => 'card',
            'metadata' => [
                'enrollment_data' => [
                    'paymentInformation' => [
                        'card' => ['bin' => '407678', 'type' => 'visa'],
                    ],
                ],
            ],
        ]);
        $payment->setRelation('paymentMethod', null);

        $this->attachPayments($order, [$payment]);

        $result = $this->makeAction($order)->receiptData();

        $this->assertSame('VISA', $result['paymentType']);
    }

    public function testResolverFallsBackToPaymentMethodString(): void
    {
        $order = $this->makeOrderWithMetadata([]);

        $payment = new Payments([
            'status' => PaymentStatusEnum::PAID->value,
            'payment_method' => 'wallet',
        ]);
        $payment->setRelation('paymentMethod', null);

        $this->attachPayments($order, [$payment]);

        $result = $this->makeAction($order)->receiptData();

        $this->assertSame('Wallet', $result['paymentType']);
    }

    public function testResolverDefaultsToTransferenciaWhenNoPaidPayment(): void
    {
        $order = $this->makeOrderWithMetadata([]);

        $this->attachPayments($order, []);

        $result = $this->makeAction($order)->receiptData();

        $this->assertSame('Transferencia/Depósito', $result['paymentType']);
        $this->assertSame('', $result['transactionNumber']);
    }

    public function testResolverIgnoresNonPaidPayments(): void
    {
        $order = $this->makeOrderWithMetadata([]);

        $authorized = new Payments([
            'status' => PaymentStatusEnum::AUTHORIZED->value,
            'payment_method_brand' => 'VISA',
            'payment_method_last_four' => '0000',
        ]);

        $this->attachPayments($order, [$authorized]);

        $result = $this->makeAction($order)->receiptData();

        $this->assertSame('Transferencia/Depósito', $result['paymentType']);
    }

    public function testImpoundReceiptKeepsTheLegacyVoucherFilename(): void
    {
        $order = $this->makeOrderWithMetadata(['vehiclePlate' => 'A123456', 'vehicleBrand' => 'Toyota Corolla']);
        $order->order_number = 1045;
        $order->setRelation('orderType', new OrderTypes(['name' => OrderTypeEnum::IMPOUND_LOT->value]));

        $receipt = OrderTypeEnum::IMPOUND_LOT->pdfReceipt();

        $this->assertSame('1045_impound_lot_A123456_Toyota Corolla', $receipt->filenameFor($order));
        $this->assertSame('order-release-voucher', $receipt->template);
        $this->assertSame('COMPROBANTE_DESPACHO_GENERADO', $receipt->activityLog);
        $this->assertSame('voucher_url', $receipt->urlCustomField);
    }

    public function testImpoundFilenameBlanksMissingVehicleDataLikeTheLegacyVoucher(): void
    {
        $order = $this->makeOrderWithMetadata([]);
        $order->order_number = 1045;
        $order->setRelation('orderType', new OrderTypes(['name' => OrderTypeEnum::IMPOUND_LOT->value]));

        $this->assertSame('1045_impound_lot__', OrderTypeEnum::IMPOUND_LOT->pdfReceipt()->filenameFor($order));
    }

    public function testMetadataCannotOverrideTheOrderNumberToken(): void
    {
        $order = $this->makeOrderWithMetadata(['order_number' => 'spoofed', 'tag' => ['nested']]);
        $order->order_number = 77;
        $order->setRelation('orderType', null);

        $receipt = new OrderReceipt(template: 'any', filenamePattern: '{order_number}-{tag}-receipt');

        $this->assertSame('77--receipt', $receipt->filenameFor($order));
    }

    public function testOrderTypeWithoutTemplateHasNoReceipt(): void
    {
        $this->assertNull(new OrderTypes(['config' => []])->pdfReceipt());
        $this->assertNull(new OrderTypes(['config' => ['pdf_receipt' => ['template' => '  ']]])->pdfReceipt());

        $receipt = new OrderTypes(['config' => ['pdf_receipt' => ['template' => 'paso-rapido-receipt']]])->pdfReceipt();

        $this->assertSame('paso-rapido-receipt', $receipt->template);
        $this->assertSame('{order_number}', $receipt->filenamePattern);
        $this->assertSame('ORDER_RECEIPT_GENERATED', $receipt->activityLog);
        $this->assertSame('receipt_url', $receipt->urlCustomField);
    }

    public function testReceiptConfigRoundTrips(): void
    {
        $receipt = OrderTypeEnum::IMPOUND_LOT->pdfReceipt();

        $this->assertEquals($receipt, OrderReceipt::fromConfig($receipt->toConfig()));
    }

    public function testFailsClearlyWhenTheOrderTypeHasNoReceiptTemplate(): void
    {
        $order = $this->makeOrderWithMetadata([]);
        $order->setRelation('orderType', new OrderTypes(['name' => 'no-receipt', 'config' => []]));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Order type no-receipt has no PDF receipt template configured');

        $this->makeAction($order)->execute();
    }

    private function makeOrderWithMetadata(array $data): Order
    {
        $order = new Order();
        $order->metadata = ['data' => $data];

        return $order;
    }

    private function attachPayments(Order $order, array $payments): void
    {
        $order->setRelation('payments', new Collection($payments));
    }

    private function makeAction(Order $order): GenerateOrderReceiptAction
    {
        return new GenerateOrderReceiptAction($order, new Users());
    }
}
