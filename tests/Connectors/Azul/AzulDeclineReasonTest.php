<?php

declare(strict_types=1);

namespace Tests\Connectors\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
use Kanvas\Connectors\Azul\Services\AzulService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Models\PaymentMethods;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Tests\TestCase;

final class AzulDeclineReasonTest extends TestCase
{
    private const array INSUFFICIENT_FUNDS = [
        'ErrorDescription' => 'INSUF FONDOS',
        'IsoCode' => '51',
        'ResponseMessage' => 'DECLINADA',
    ];

    private const array INVALID_CARD = [
        'ErrorDescription' => 'SGS-002303: Invalid credit card number',
        'IsoCode' => '99',
        'ResponseMessage' => 'ERROR',
    ];

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testStartChallengeDeclineCarriesTheAzulErrorDescription(): void
    {
        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('sale3DS')->once()->andThrow($this->decline(self::INSUFFICIENT_FUNDS));

        $result = $this->processor($service)->startChallenge(
            $this->payment(),
            $this->order(),
            ['use_hold' => false, 'card_holder_info' => [], 'browser_info' => ['ip_address' => '127.0.0.1']]
        );

        $this->assertFalse($result->success);
        $this->assertSame('DECLINADA | INSUF FONDOS', $result->message);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $result->status);
        $this->assertSame('51', $result->raw['IsoCode']);
    }

    public function testFinalizeChallengeDeclineCarriesTheAzulErrorDescription(): void
    {
        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('processThreeDSMethod')->once()->andThrow($this->decline(self::INVALID_CARD));

        $payment = $this->payment();
        $payment->payment_intent_id = 'AZ-1';
        $payment->status = PaymentStatusEnum::PENDING_AUTHORIZATION->value;
        $payment->metadata = ['data' => ['3ds_pa_res' => 'cres']];

        $result = $this->processor($service)->finalizeChallenge($payment, $this->order());

        $this->assertFalse($result->success);
        $this->assertSame('ERROR | SGS-002303: Invalid credit card number', $result->message);
        $this->assertSame('99', $result->raw['IsoCode']);
    }

    public function testAuthorizeDeclineWithoutADescriptionKeepsTheResponseMessage(): void
    {
        $service = Mockery::mock(AzulService::class);
        $service->shouldReceive('sale')->once()->andThrow($this->decline(['ResponseMessage' => 'DECLINADA']));

        $result = $this->processor($service)->authorize($this->payment(), $this->order(), ['use_hold' => false]);

        $this->assertFalse($result->success);
        $this->assertSame('DECLINADA', $result->message);
    }

    private function decline(array $body): AzulException
    {
        return new AzulException(message: $body['ResponseMessage'], errorBody: $body);
    }

    private function processor(AzulService $service): AzulProcessor
    {
        return new AzulProcessor(app(Apps::class), Companies::first(), $service);
    }

    private function order(): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 1;
        $order->currency = 'DOP';
        $order->order_number = 'ORD-1';
        $order->shouldReceive('getTotalDueAmount')->andReturn(100.0);
        $order->shouldReceive('getTotalTaxAmount')->andReturn(0.0);

        return $order;
    }

    private function payment(): Payments
    {
        $paymentMethod = Mockery::mock(PaymentMethods::class)->makePartial();
        $paymentMethod->shouldReceive('getMetadata')->andReturn('vault-token');

        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->id = 1;
        $payment->status = PaymentStatusEnum::PROCESSING->value;
        $payment->setRelation('paymentMethod', $paymentMethod);
        $payment->shouldReceive('save')->andReturn(true);
        $payment->shouldReceive('addLog')->andReturn(null);

        return $payment;
    }
}
