<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Azul;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Azul\Enums\CustomFieldEnum;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Contracts\PaymentProcessorInterface;
use Kanvas\Souk\Payments\Contracts\TokenizationProcessorInterface;
use Kanvas\Souk\Payments\DataTransferObject\AuthorizeResult;
use Kanvas\Souk\Payments\DataTransferObject\CaptureResult;
use Kanvas\Souk\Payments\DataTransferObject\RefundResult;
use Kanvas\Souk\Payments\DataTransferObject\TokenizeResult;
use Kanvas\Souk\Payments\DataTransferObject\VoidResult;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Azul\AzulProcessor;
use Kanvas\Souk\Payments\Processors\ProcessorFactory;
use Mockery;
use Mockery\MockInterface;

final class AzulProcessorTest extends AzulBase
{
    private AzulProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->processor = new AzulProcessor(
            app(Apps::class),
            Companies::first(),
        );
    }

    // -------------------------------------------------------------------------
    // Capability contracts
    // -------------------------------------------------------------------------

    public function testImplementsPaymentProcessorInterface(): void
    {
        $this->assertInstanceOf(PaymentProcessorInterface::class, $this->processor);
    }

    public function testImplementsTokenizationProcessorInterface(): void
    {
        $this->assertInstanceOf(TokenizationProcessorInterface::class, $this->processor);
    }

    public function testProcessorFactoryResolvesAzul(): void
    {
        $processor = ProcessorFactory::make('azul', app(Apps::class), Companies::first());
        $this->assertInstanceOf(AzulProcessor::class, $processor);
    }

    // -------------------------------------------------------------------------
    // authorize()
    // -------------------------------------------------------------------------

    public function testAuthorizeWithCardChargesSuccessfully(): void
    {
        [$payment, $order] = $this->buildMockedPaymentAndOrder();

        $result = $this->processor->authorize($payment, $order);

        $this->assertInstanceOf(AuthorizeResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->transactionId);
        $this->assertEquals(PaymentStatusEnum::PAID, $result->paymentStatus);
    }

    public function testAuthorizeWithDataVaultToken(): void
    {
        // First: get a real vault token from a sale
        $service = $this->getService();
        $saleRequest = $this->buildSaleRequest(['saveToDataVault' => '1']);
        $saleResponse = $service->sale($saleRequest);
        $this->assertTrue($saleResponse->isApproved());
        $token = $saleResponse->dataVaultToken;

        [$payment, $order] = $this->buildMockedPaymentAndOrder(dataVaultToken: $token);

        $result = $this->processor->authorize($payment, $order);

        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->transactionId);
    }

    // -------------------------------------------------------------------------
    // capture()
    // -------------------------------------------------------------------------

    public function testCaptureIsNoOp(): void
    {
        [$payment, $order] = $this->buildMockedPaymentAndOrder();

        // capture() should return success without hitting Azul API
        $result = $this->processor->capture($payment, $order);

        $this->assertInstanceOf(CaptureResult::class, $result);
        $this->assertTrue($result->success);
    }

    // -------------------------------------------------------------------------
    // void()
    // -------------------------------------------------------------------------

    public function testVoidReturnsUnsupportedResult(): void
    {
        [$payment, $order] = $this->buildMockedPaymentAndOrder();

        $result = $this->processor->void($payment, $order);

        $this->assertInstanceOf(VoidResult::class, $result);
        $this->assertFalse($result->success);
    }

    // -------------------------------------------------------------------------
    // refund()
    // -------------------------------------------------------------------------

    public function testRefundAfterSuccessfulCharge(): void
    {
        [$payment, $order] = $this->buildMockedPaymentAndOrder();

        $authResult = $this->processor->authorize($payment, $order);
        $this->assertTrue($authResult->success);

        // refund() reads AZUL_ORDER_ID from the order — already set by authorize()
        $refundResult = $this->processor->refund($payment, $order);

        $this->assertInstanceOf(RefundResult::class, $refundResult);
        $this->assertTrue($refundResult->success);
        $this->assertNotEmpty($refundResult->transactionId);
    }

    public function testRefundFailsWhenNoAzulOrderId(): void
    {
        [$payment, $order] = $this->buildMockedPaymentAndOrder(azulOrderId: null);

        $result = $this->processor->refund($payment, $order);

        $this->assertFalse($result->success);
    }

    // -------------------------------------------------------------------------
    // tokenize()
    // -------------------------------------------------------------------------

    public function testTokenizeStoresCardInDataVault(): void
    {
        $result = $this->processor->tokenize([
            'number'     => '6011000000000004',
            'expiration' => '202812',
            'cvc'        => '818',
            'brand'      => 'discover',
        ]);

        $this->assertInstanceOf(TokenizeResult::class, $result);
        $this->assertTrue($result->success);
        $this->assertNotEmpty($result->token);
        $this->assertEquals('0004', $result->lastFour);
    }

    public function testDeleteTokenReturnsFalseForInvalidToken(): void
    {
        // Azul returns an error for a non-existent token; processor catches it and returns false.
        $this->assertFalse($this->processor->deleteToken('INVALID-TOKEN-000'));
    }

    public function testDeleteTokenAfterTokenize(): void
    {
        $tokenizeResult = $this->processor->tokenize([
            'number'     => '6011000000000004',
            'expiration' => '202812',
            'cvc'        => '818',
            'brand'      => 'discover',
        ]);

        $this->assertTrue($tokenizeResult->success);
        $this->assertTrue($this->processor->deleteToken($tokenizeResult->token));
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build lightweight mock Payment and Order objects for the processor tests.
     * The mocks satisfy the interface calls made inside AzulProcessor methods.
     */
    private function buildMockedPaymentAndOrder(
        ?string $dataVaultToken = null,
        ?string $azulOrderId = null,
    ): array {
        $credentials = $this->getCredentials();
        $store = ['azul_order_id' => $azulOrderId];

        /** @var MockInterface $paymentMethod */
        $paymentMethod = Mockery::mock(\Kanvas\Payments\Models\PaymentMethods::class)->makePartial();
        $paymentMethod->metadata = [
            'card_number' => '6011000000000004',
            'expiration'  => '202812',
            'cvc'         => '818',
            CustomFieldEnum::AZUL_DATA_VAULT_TOKEN->value => $dataVaultToken,
        ];
        $paymentMethod->shouldReceive('getMetadata')->andReturnUsing(
            fn ($key) => $paymentMethod->metadata[$key] ?? null
        );
        $paymentMethod->shouldReceive('save')->andReturn(true);

        /** @var MockInterface $payment */
        $payment = Mockery::mock(\Kanvas\Souk\Payments\Models\Payments::class)->makePartial();
        $payment->apps_id = 1;
        $payment->companies_id = 1;
        $payment->shouldReceive('paymentMethod')->andReturn($paymentMethod);
        $payment->shouldReceive('getAttribute')->with('paymentMethod')->andReturn($paymentMethod);
        $payment->shouldReceive('markAsPaid')->andReturn(true);
        $payment->shouldReceive('addMetadata')->andReturn(null);
        $payment->shouldReceive('addLog')->andReturn(null);
        $payment->shouldReceive('update')->andReturn(true);

        /** @var MockInterface $order */
        $order = Mockery::mock(Order::class)->makePartial();
        $order->id = 1;
        $order->order_number = 'TEST-' . time();
        $order->shouldReceive('getTotalAmount')->andReturn(1.00);
        $order->shouldReceive('getTotalTaxAmount')->andReturn(0.18);
        $order->shouldReceive('set')->andReturnUsing(function ($key, $value) use (&$store) {
            $store[$key] = $value;
        });
        $order->shouldReceive('get')->andReturnUsing(
            fn ($key) => $store[$key] ?? null
        );

        return [$payment, $order];
    }
}
