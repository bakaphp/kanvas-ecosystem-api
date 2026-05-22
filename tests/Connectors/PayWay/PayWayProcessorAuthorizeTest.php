<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay;

use DomainException;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\ThreeDSProcessorInterface;
use Kanvas\Souk\Payments\Infrastructure\Processors\PayWay\PayWayProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Tests\Connectors\PayWay\Fakes\FakePayWayClient;
use Tests\TestCase;

class PayWayProcessorAuthorizeTest extends TestCase
{
    private PayWayProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = new PayWayProcessor(
            app: app(Apps::class),
            company: Companies::first(),
            client: new FakePayWayClient(),
        );
    }

    public function testAuthorizeAlwaysThrows(): void
    {
        $payment = new Payments();
        $order = new Order();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('PayWay requires 3DS');

        $this->processor->authorize($payment, $order);
    }

    public function testAuthorizeThrowsWithContext(): void
    {
        $payment = new Payments();
        $order = new Order();

        $this->expectException(DomainException::class);

        $this->processor->authorize($payment, $order, ['card' => ['number' => '4111111111111111']]);
    }

    public function testNameReturnsPayway(): void
    {
        $this->assertSame('payway', $this->processor->name());
    }

    public function testImplementsPaymentProcessorInterface(): void
    {
        $this->assertInstanceOf(PaymentProcessorInterface::class, $this->processor);
    }

    public function testImplementsThreeDSProcessorInterface(): void
    {
        $this->assertInstanceOf(ThreeDSProcessorInterface::class, $this->processor);
    }
}
