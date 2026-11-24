<?php

declare(strict_types=1);

namespace Tests\Souk\Unit;

use Kanvas\Connectors\EchoPay\DataTransferObject\BillingDetail;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\DataTransferObject\MerchantDetail;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Souk\Payments\Providers\PortalPaymentProcessor;
use Mockery;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCaseUnit;

final class PortalPaymentProcessorAmountTest extends TestCaseUnit
{
    private array $sent = [];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testCaptureSendsNetAmountAfterOrderDiscount(): void
    {
        $processor = $this->processorWithClient(
            'capturePayment',
            ['status' => 'CAPTURED']
        );

        $processor->capturePayment($this->payment(), $this->order(gross: 100.0, net: 70.0), 'tx-1');

        $this->assertCount(1, $this->sent);
        $this->assertEquals(70.0, (float) $this->sent[0]->totalAmount);
    }

    public function testCaptureSendsFullAmountWhenOrderHasNoDiscount(): void
    {
        $processor = $this->processorWithClient(
            'capturePayment',
            ['status' => 'CAPTURED']
        );

        $processor->capturePayment($this->payment(), $this->order(gross: 100.0, net: 100.0), 'tx-1');

        $this->assertEquals(100.0, (float) $this->sent[0]->totalAmount);
    }

    public function testReverseSendsNetAmountAfterOrderDiscount(): void
    {
        $processor = $this->processorWithClient(
            'reversePayment',
            ['status' => 'REVERSED']
        );

        $processor->reversePayment($this->payment(), $this->order(gross: 100.0, net: 70.0), 'tx-1', 'test');

        $this->assertCount(1, $this->sent);
        $this->assertEquals(70.0, (float) $this->sent[0]->totalAmount);
    }

    public function testAuthorizeSendsNetAmountAfterOrderDiscount(): void
    {
        $processor = $this->processorWithClient(
            'authorizePayment',
            ['status' => 'AUTHORIZED']
        );
        $consumer = Mockery::mock(ConsumerAuthentication::class);

        $result = new ReflectionMethod($processor, 'processPaymentCall')
            ->invoke($processor, $this->payment(), $consumer, $this->order(gross: 100.0, net: 70.0));

        $this->assertSame('success', $result['status']);
        $this->assertCount(1, $this->sent);
        $this->assertEquals(70.0, (float) $this->sent[0]->orderInformation->totalAmount);
    }

    private function processorWithClient(string $method, array $response): StubbedPortalPaymentProcessor
    {
        $merchant = new MerchantDetail(
            id: 'merchant-1',
            key: 'key',
            secretKey: 'secret',
            merchantDefinedInformation: null,
        );

        $client = Mockery::mock(EchoPayService::class);
        $client->shouldReceive($method)
            ->once()
            ->andReturnUsing(function (object $input) use ($response): array {
                $this->sent[] = $input;

                return $response;
            });

        $billing = Mockery::mock(BillingDetail::class);
        $processor = new ReflectionClass(StubbedPortalPaymentProcessor::class)->newInstanceWithoutConstructor();
        $processor->merchant = $merchant;
        $processor->billing = $billing;

        new ReflectionClass(PortalPaymentProcessor::class)->getProperty('client')->setValue($processor, $client);

        return $processor;
    }

    private function payment(): Payments
    {
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->shouldReceive('addLog')->andReturnNull();
        $payment->shouldReceive('markAsPaid')->andReturnNull();
        $payment->shouldReceive('addMetadata')->andReturnNull();
        $payment->shouldReceive('save')->andReturnSelf();
        $payment->shouldReceive('claimForSettlement')->andReturnTrue();
        $payment->shouldReceive('releaseSettlementClaim')->andReturnTrue();
        $payment->exists = true;
        $paymentMethod = new PaymentMethods();
        $paymentMethod->stripe_card_id = 'card-1';
        $payment->setRelation('paymentMethod', $paymentMethod);

        return $payment;
    }

    private function order(float $gross, float $net): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 1;
        $order->total_gross_amount = $gross;
        $order->total_net_amount = $net;
        $order->shouldReceive('get')->andReturn('session-1');

        return $order;
    }
}

class StubbedPortalPaymentProcessor extends PortalPaymentProcessor
{
    public MerchantDetail $merchant;
    public BillingDetail $billing;

    protected function setupMerchantAuthentication(Payments $payment, Order $order, bool $includeDetails = false): MerchantDetail
    {
        return $this->merchant;
    }

    protected function setCustomerBillingAddress(Payments $payment, Order $orderInput): BillingDetail
    {
        return $this->billing;
    }
}
