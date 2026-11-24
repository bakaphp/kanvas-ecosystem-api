<?php

declare(strict_types=1);

namespace Tests\Souk\Payments;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentRequest;
use Kanvas\Connectors\Azul\DataTransferObject\AzulPaymentResponse;
use Kanvas\Connectors\Azul\Enums\CustomFieldEnum;
use Kanvas\Connectors\Azul\Services\AzulService;
use Kanvas\CustomFields\Models\AppsCustomFields;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Mockery\MockInterface;
use Tests\TestCase;

final class AzulItbisFromTaxAmountTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testItbisIsBuiltFromOrderTaxAmountNotHardcodedZero(): void
    {
        $capturedRequest = null;
        [$processor, $payment, $order] = $this->buildProcessorWithMocks(
            taxAmount: 18.00,
            capturedRequest: $capturedRequest,
        );

        $result = $processor->authorize($payment, $order);

        $this->assertTrue($result->success);
        $this->assertNotNull($capturedRequest, 'sale() was never called — authorize() did not reach the provider.');
        $this->assertSame(
            '1800',
            $capturedRequest->itbis,
            'ITBIS must be 18.00 in cents (1800), not "000" or any hardcoded zero value.'
        );
    }

    public function testItbisIsZeroStringWhenOrderHasNoTax(): void
    {
        $capturedRequest = null;
        [$processor, $payment, $order] = $this->buildProcessorWithMocks(
            taxAmount: 0.0,
            capturedRequest: $capturedRequest,
        );

        $processor->authorize($payment, $order);

        $this->assertNotNull($capturedRequest);
        $this->assertSame('0', $capturedRequest->itbis);
    }

    private function buildProcessorWithMocks(float $taxAmount, mixed &$capturedRequest): array
    {
        $app = app(Apps::class);
        $company = Companies::first();

        $approvedResponse = new AzulPaymentResponse(
            authorizationCode: 'AUTH-TEST',
            azulOrderId: 'AZUL-TEST',
            isoCode: '00',
            responseMessage: 'APROBADA',
        );

        /** @var MockInterface $mockService */
        $mockService = Mockery::mock(AzulService::class);
        $mockService->shouldReceive('sale')
            ->once()
            ->andReturnUsing(function (AzulPaymentRequest $request) use (&$capturedRequest, $approvedResponse) {
                $capturedRequest = $request;

                return $approvedResponse;
            });

        /** @var MockInterface $paymentMethod */
        $paymentMethod = Mockery::mock(PaymentMethods::class)->makePartial();
        $paymentMethod->metadata = [
            'card_number' => '6011000000000004',
            'expiration' => '202812',
            'cvc' => '818',
            CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value => null,
        ];
        $paymentMethod->shouldReceive('getMetadata')->andReturnUsing(
            fn ($key) => $paymentMethod->metadata[$key] ?? null
        );
        $paymentMethod->shouldReceive('save')->andReturn(true);

        /** @var MockInterface $payment */
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->apps_id = $app->getId();
        $payment->companies_id = $company->getId();
        $payment->shouldReceive('paymentMethod')->andReturn($paymentMethod);
        $payment->shouldReceive('getAttribute')->with('paymentMethod')->andReturn($paymentMethod);
        $payment->shouldReceive('markAsPaid')->andReturn(true);
        $payment->shouldReceive('addMetadata')->andReturn(null);
        $payment->shouldReceive('addLog')->andReturn(null);
        $payment->shouldReceive('update')->andReturn(true);

        $store = [];

        /** @var MockInterface $order */
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 999;
        $order->order_number = 'TEST-ITBIS-' . time();
        $order->currency = 'DOP';
        $order->shouldReceive('getTotalDueAmount')->andReturn(100.00 + $taxAmount);
        $order->shouldReceive('getTotalTaxAmount')->andReturn($taxAmount);
        $order->shouldReceive('set')->andReturnUsing(function ($key, $value) use (&$store) {
            $store[$key] = $value;

            return new AppsCustomFields();
        });
        $order->shouldReceive('get')->andReturnUsing(fn ($key) => $store[$key] ?? null);

        $processor = new AzulProcessor($app, $company, $mockService);

        return [$processor, $payment, $order];
    }
}
