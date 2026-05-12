<?php

declare(strict_types=1);

namespace Tests\Souk\Orders;

use Illuminate\Support\Collection;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Jobs\GenerateOrderReceiptPdfJob;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class GenerateOrderReceiptPdfJobTest extends TestCase
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

        $result = $this->makeJob($order)->resolveVoucherData($order);

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

        $result = $this->makeJob($order)->resolveVoucherData($order);

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
                        'card' => ['bin' => '407678', 'type' => 'VISA'],
                    ],
                ],
            ],
        ]);
        $payment->setRelation('paymentMethod', null);

        $this->attachPayments($order, [$payment]);

        $result = $this->makeJob($order)->resolveVoucherData($order);

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

        $result = $this->makeJob($order)->resolveVoucherData($order);

        $this->assertSame('Wallet', $result['paymentType']);
    }

    public function testResolverDefaultsToTransferenciaWhenNoPaidPayment(): void
    {
        $order = $this->makeOrderWithMetadata([]);

        $this->attachPayments($order, []);

        $result = $this->makeJob($order)->resolveVoucherData($order);

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

        $result = $this->makeJob($order)->resolveVoucherData($order);

        $this->assertSame('Transferencia/Depósito', $result['paymentType']);
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

    private function makeJob(Order $order): GenerateOrderReceiptPdfJob
    {
        return new GenerateOrderReceiptPdfJob(
            $order,
            new Users(),
            'order-release-voucher',
            'test-voucher',
            [],
        );
    }
}
