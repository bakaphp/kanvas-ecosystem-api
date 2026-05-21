<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use Kanvas\Connectors\PayWay\Enums\ConfigurationEnum;
use Kanvas\Connectors\PayWay\Enums\CustomFieldEnum;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\PayWay\PayWayProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Tests\Connectors\PayWay\Fakes\FakePayWayClient;
use Tests\TestCase;

class PayWayProcessor3DSTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;
    private FakePayWayClient $fakeClient;
    private PayWayProcessor $processor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();

        $this->currentCompany->set(ConfigurationEnum::PAYWAY_ENCRYPTION_KEY->value, 'test-secret-key-32bytes-padded!!');

        $this->fakeClient = new FakePayWayClient();
        $this->processor = new PayWayProcessor(
            app: $this->currentApp,
            company: $this->currentCompany,
            client: $this->fakeClient,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testStartChallengeRawCardHappyPathTransitionsToWaitingDeviceData(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value);
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('payUsing3ds', PayWayResponse::fromArray([
            'returnCode' => '01',
            'message' => 'Step 1 — device data required',
            'success' => false,
            'tokenAcceso' => 'token-abc-123',
            'urlColeccionDatoDispositivo' => 'https://payway.sv/collect',
            'referenciaId' => 'ref-999',
            'numeroTransaccionSistema' => 'NTS-001',
            'numeroTransaccionReferencia' => 'NTR-001',
        ]));

        $result = $this->processor->startChallenge($payment, $order, [
            'card' => [
                'number' => '4111111111111111',
                'cvv' => '123',
                'expiration_date' => '2612',
                'firstname' => 'John',
                'lastname' => 'Doe',
            ],
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('payway_waiting_device_data', $result->status);
        $this->assertSame('token-abc-123', $result->data['tokenAcceso']);
        $this->assertSame('https://payway.sv/collect', $result->data['urlColeccionDatoDispositivo']);
        $this->assertSame('ref-999', $result->data['referenciaId']);

        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $payment->status);

        $calls = $this->fakeClient->getCalls('payUsing3ds');
        $this->assertCount(1, $calls);
        $this->assertArrayHasKey('tarjetaNumero', $calls[0]['payload']);
        $this->assertArrayHasKey('tarjetaCvv2', $calls[0]['payload']);
        $this->assertArrayNotHasKey('tarjetaUuid', $calls[0]['payload']);
    }

    public function testStartChallengeSavedCardPathUsesTarjetaUuid(): void
    {
        $paymentMethod = $this->buildPaymentMethodMock('uuid-fixture-value');
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value, $paymentMethod);
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('payUsing3ds', PayWayResponse::fromArray([
            'returnCode' => '01',
            'message' => 'Step 1 OK',
            'success' => false,
            'tokenAcceso' => 'token-saved',
            'urlColeccionDatoDispositivo' => 'https://payway.sv/collect',
            'referenciaId' => 'ref-saved',
            'numeroTransaccionSistema' => 'NTS-002',
            'numeroTransaccionReferencia' => 'NTR-002',
        ]));

        $result = $this->processor->startChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('payway_waiting_device_data', $result->status);

        $calls = $this->fakeClient->getCalls('payUsing3ds');
        $this->assertCount(1, $calls);
        $this->assertSame('uuid-fixture-value', $calls[0]['payload']['tarjetaUuid']);
        $this->assertArrayNotHasKey('tarjetaNumero', $calls[0]['payload']);
        $this->assertArrayNotHasKey('tarjetaCvv2', $calls[0]['payload']);
    }

    public function testStartChallengeDeclineTransitionsToFailed(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING->value);
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('payUsing3ds', PayWayResponse::fromArray([
            'returnCode' => '02',
            'message' => 'Tarjeta denegada por el emisor',
            'success' => false,
        ]));

        $result = $this->processor->startChallenge($payment, $order, [
            'card' => [
                'number' => '4111111111111111',
                'cvv' => '123',
                'expiration_date' => '2612',
            ],
        ]);

        $this->assertFalse($result->success);
        $this->assertSame('payway_terminal', $result->status);
        $this->assertStringContainsString('denegada', $result->message);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $payment->status);
        $this->assertNotEmpty($payment->metadata['data']['payway_error'] ?? null);
    }

    public function testFinalizeChallengeEscenario1TerminalPaid(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $payment->metadata = [
            'data' => [
                'payway_seguimiento' => ['dummy' => 'seguimiento-data'],
                'payway_token_acceso' => 'token-abc',
            ],
        ];
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('completePayment', PayWayResponse::fromArray([
            'returnCode' => '00',
            'message' => 'Pago aprobado',
            'success' => true,
            'numeroUuid' => 'uuid-final-123',
            'numeroCompra' => 'compra-456',
            'numeroAutorizacion' => 'auth-789',
        ]));

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('payway_terminal', $result->status);
        $this->assertSame('uuid-final-123', $result->data['payway_numero_uuid']);

        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertSame('uuid-final-123', $payment->metadata['data']['payway_numero_uuid'] ?? null);
        $this->assertSame('compra-456', $payment->metadata['data']['payway_numero_compra'] ?? null);
        $this->assertSame('auth-789', $payment->metadata['data']['payway_numero_autorizacion'] ?? null);

        // Transient metadata cleared.
        $this->assertArrayNotHasKey('payway_token_acceso', $payment->metadata['data'] ?? []);
        $this->assertArrayNotHasKey('payway_seguimiento', $payment->metadata['data'] ?? []);
    }

    public function testFinalizeChallengeEscenario2OtpRequired(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $payment->metadata = [
            'data' => [
                'payway_seguimiento' => ['dummy' => 'seguimiento-data'],
            ],
        ];
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('completePayment', PayWayResponse::fromArray([
            'returnCode' => '01',
            'message' => 'OTP required',
            'success' => false,
            'paso' => 4,
            'tokenAcceso' => 'otp-token-abc',
            'urlColeccionDatoDispositivo' => 'https://payway.sv/otp',
        ]));

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('payway_pending_otp', $result->status);
        $this->assertSame('otp-token-abc', $result->data['tokenAcceso']);
        $this->assertSame(4, $result->data['paso']);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);
        $this->assertSame('otp-token-abc', $payment->metadata['data']['payway_otp_token_acceso'] ?? null);
    }

    public function testFinalizeChallengeSecondCallAfterOtpTransitionsToPaid(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::WAITING_DEVICE_DATA->value);
        $payment->metadata = ['data' => ['payway_seguimiento' => ['dummy' => 'data']]];
        $order = $this->buildOrderMock();

        $this->fakeClient->queueResponse('completePayment', PayWayResponse::fromArray([
            'returnCode' => '01',
            'message' => 'OTP required',
            'success' => false,
            'paso' => 4,
            'tokenAcceso' => 'otp-token-abc',
            'urlColeccionDatoDispositivo' => 'https://payway.sv/otp',
        ]));

        $firstResult = $this->processor->finalizeChallenge($payment, $order);
        $this->assertSame('payway_pending_otp', $firstResult->status);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);

        // Second call — FE signals OTP completed with terminal state.
        $secondResult = $this->processor->finalizeChallenge($payment, $order, [
            'otp_terminal_status' => 'PAID',
        ]);

        $this->assertTrue($secondResult->success);
        $this->assertSame('payway_terminal', $secondResult->status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testFinalizeChallengeOtpTerminalPaidFallsBackToTraceWhenTrxInfoMissing(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $payment->metadata = ['data' => ['payway_otp_token_acceso' => 'otp-jwt']];
        $order = $this->buildOrderMock();

        // FE listener didn't forward trxInformacion (manual fallback button), so the
        // processor must reconcile by traceId via /payments/trace/{traceId}.
        $this->fakeClient->queueResponse('traceTransaction', PayWayResponse::fromArray([
            'returnCode' => '00',
            'message' => 'TRANSACCION EXITOSA',
            'success' => true,
            'numeroCompra' => 'compra-from-trace',
            'numeroAutorizacion' => 'auth-from-trace',
            'numeroPayWay' => 'pw-from-trace',
            'marcaTarjeta' => 'visa',
            'terminacionTarjeta' => 'X-1841',
        ]));

        $result = $this->processor->finalizeChallenge($payment, $order, [
            'otp_terminal_status' => 'PAID',
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('payway_terminal', $result->status);
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);

        // Standard columns now populated from the trace response.
        $this->assertSame('compra-from-trace', $payment->payment_intent_id);
        $this->assertSame('auth-from-trace', $payment->authorization_code);
        $this->assertSame('pw-from-trace', $payment->number);
        $this->assertSame('visa', $payment->payment_method_brand);
        $this->assertSame('1841', $payment->payment_method_last_four);

        // traceTransaction must have been called exactly once with the payment's idempotency_key.
        $traceCalls = $this->fakeClient->getCalls('traceTransaction');
        $this->assertCount(1, $traceCalls);
        $this->assertSame('aaaabbbb-cccc-4000-8000-ddddeeeeffff', $traceCalls[0]['path'] ?? null);
    }

    public function testFinalizeChallengeOtpTerminalPaidSkipsTraceWhenTrxInfoForwarded(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PENDING_AUTHORIZATION->value);
        $payment->metadata = ['data' => ['payway_otp_token_acceso' => 'otp-jwt']];
        $order = $this->buildOrderMock();

        $result = $this->processor->finalizeChallenge($payment, $order, [
            'otp_terminal_status' => 'PAID',
            'trxInformacion' => [
                'numeroCompra' => 'compra-from-fe',
                'numeroAutorizacion' => 'auth-from-fe',
                'numeroPayWay' => 'pw-from-fe',
            ],
        ]);

        $this->assertTrue($result->success);
        $this->assertSame('compra-from-fe', $payment->payment_intent_id);
        $this->assertSame('auth-from-fe', $payment->authorization_code);
        $this->assertCount(0, $this->fakeClient->getCalls('traceTransaction'));
    }

    public function testFinalizeChallengeIdempotentOnAlreadyPaid(): void
    {
        $payment = $this->buildPaymentMock(PaymentStatusEnum::PAID->value);
        $payment->metadata = [
            'data' => [
                'payway_numero_uuid' => 'uuid-already-stored',
                'payway_numero_compra' => 'compra-already-stored',
            ],
        ];
        $order = $this->buildOrderMock();

        $result = $this->processor->finalizeChallenge($payment, $order);

        $this->assertTrue($result->success);
        $this->assertSame('payway_terminal', $result->status);
        $this->assertStringContainsString('terminal state', $result->message);

        // No PayWay calls were made.
        $this->assertCount(0, $this->fakeClient->getCalls('completePayment'));
    }

    private function buildPaymentMock(
        string $initialStatus,
        ?PaymentMethods $paymentMethod = null,
    ): Payments {
        $payment = Mockery::mock(Payments::class)->makePartial();
        $payment->exists = true;
        $payment->id = 1;
        $payment->idempotency_key = 'aaaabbbb-cccc-4000-8000-ddddeeeeffff';
        $payment->status = $initialStatus;
        $payment->metadata = [];

        $paymentMethod ??= $this->buildPaymentMethodMock(null);

        $payment->shouldReceive('paymentMethod')
            ->andReturn($paymentMethod)
            ->byDefault();

        $payment->shouldReceive('getAttribute')
            ->with('paymentMethod')
            ->andReturn($paymentMethod)
            ->byDefault();

        $payment->shouldReceive('getRelationValue')
            ->with('paymentMethod')
            ->andReturn($paymentMethod)
            ->byDefault();

        $payment->shouldReceive('save')->andReturnSelf()->byDefault();
        $payment->shouldReceive('saveQuietly')->andReturnSelf()->byDefault();

        $payment->shouldReceive('addMetadata')
            ->andReturnUsing(function (array $data) use ($payment) {
                $existing = $payment->metadata ?? [];
                $payment->metadata = [
                    ...$existing,
                    ...$data,
                    'data' => [
                        ...($existing['data'] ?? []),
                        ...($data['data'] ?? []),
                    ],
                ];
            })
            ->byDefault();

        $payment->shouldReceive('getMetadata')
            ->andReturnUsing(function (string $key) use ($payment) {
                return $payment->metadata['data'][$key] ?? null;
            })
            ->byDefault();

        $payment->shouldReceive('addLog')
            ->andReturnNull()
            ->byDefault();

        $payment->shouldReceive('markAsPaid')
            ->andReturnUsing(function (array $metadata = []) use ($payment) {
                $payment->status = PaymentStatusEnum::PAID->value;
                if (! empty($metadata)) {
                    $existing = $payment->metadata ?? [];
                    $payment->metadata = [
                        ...$existing,
                        ...$metadata,
                        'data' => [
                            ...($existing['data'] ?? []),
                            ...($metadata['data'] ?? []),
                        ],
                    ];
                }
            })
            ->byDefault();

        return $payment;
    }

    private function buildPaymentMethodMock(?string $numeroUuid): PaymentMethods
    {
        $pm = Mockery::mock(PaymentMethods::class)->makePartial();
        $pm->exists = true;
        $pm->id = 1;
        $pm->metadata = $numeroUuid !== null
            ? [CustomFieldEnum::PAYWAY_NUMERO_UUID->value => $numeroUuid]
            : [];

        $pm->shouldReceive('getMetadata')
            ->andReturnUsing(function (string $key) use ($pm) {
                return $pm->metadata[$key] ?? null;
            })
            ->byDefault();

        $pm->shouldReceive('save')->andReturnSelf()->byDefault();

        return $pm;
    }

    private function buildOrderMock(): Order
    {
        $order = Mockery::mock(Order::class)->makePartial();
        $order->exists = true;
        $order->id = 1;
        $order->order_number = 12345;
        $order->ip_address = '192.168.1.1';
        $order->user_email = 'test@example.com';
        $order->currency = 'USD';
        $order->total_gross_amount = 10.00;
        $order->total_net_amount = 10.00;

        $order->shouldReceive('getTotalAmount')
            ->andReturn(10.00)
            ->byDefault();

        return $order;
    }
}
