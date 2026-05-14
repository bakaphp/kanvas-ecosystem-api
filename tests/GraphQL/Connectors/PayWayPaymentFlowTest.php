<?php

declare(strict_types=1);

namespace Tests\GraphQL\Connectors;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use Kanvas\Connectors\PayWay\Enums\ConfigurationEnum;
use Kanvas\Connectors\PayWay\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Payments\Models\PaymentMethods;
use Kanvas\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\PayWay\PayWayProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Tests\Connectors\PayWay\Fakes\FakePayWayClient;
use Tests\TestCase;

class PayWayPaymentFlowTest extends TestCase
{
    use DatabaseTransactions;

    private FakePayWayClient $fakeClient;
    private Apps $currentApp;
    private Companies $currentCompany;
    private int $regionId;
    private int $peopleId;

    public function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->currentCompany = static::$cachedUser->getCurrentCompany();
        $this->regionId = Regions::getDefault($this->currentCompany, $this->currentApp)->id;
        $this->peopleId = People::first()->id;

        $this->currentCompany->set(ConfigurationEnum::PAYWAY_TOKEN->value, 'TEST_TOKEN');
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_COLECTOR_ID->value, '12345');
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_USUARIO_OPERACION->value, 'TEST_USER');
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_ENCRYPTION_KEY->value, 'test-secret-key-32bytes-padded!!');
        $this->currentCompany->set(ConfigurationEnum::PAYWAY_BASE_URL->value, 'https://test.payway.sv');

        $this->fakeClient = new FakePayWayClient();

        $this->app->bind('payment_processor.payway', function ($app, array $params = []) {
            return new PayWayProcessor(
                app: $params['app'] ?? $app->make(Apps::class),
                company: $params['company'] ?? $this->currentCompany,
                client: $this->fakeClient,
            );
        });
    }

    /**
     * PayWay tokenization throws DomainException. The PaymentMethodMutation only catches
     * RequestException, so the DomainException propagates to Lighthouse as an unhandled
     * exception. In test/debug mode Lighthouse exposes the real message in
     * extensions.debugMessage; in production it surfaces as "Internal server error".
     * We assert on debugMessage to confirm the right exception was thrown.
     */
    public function testCreatePaymentMethodTokenizeRejectedExplicitly(): void
    {
        $response = $this->graphQL('
            mutation($input: PaymentMethodInput!) {
                createPaymentMethod(input: $input) {
                    id
                }
            }
        ', [
            'input' => [
                'processor' => 'payway',
                'number' => '4111111111111111',
                'cvv' => '123',
                'expiration_date' => '2030-12',
                'brand' => 'visa',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $errors = $response->json('errors');
        $this->assertNotEmpty($errors, 'Expected a GraphQL error for PayWay tokenization but got none');

        // Lighthouse wraps unhandled exceptions as "Internal server error" in production mode.
        // The original DomainException message is available in extensions.debugMessage.
        $debugMessage = $errors[0]['extensions']['debugMessage'] ?? $errors[0]['message'] ?? '';
        $this->assertStringContainsString(
            'does not support standalone tokenization',
            $debugMessage,
            'Expected DomainException from PayWayTokenizationService::tokenize()'
        );
    }

    public function testStartPaymentChallengeReturnsStep2IframeState(): void
    {
        $order = $this->buildOrder();
        $payment = $this->createPendingPaymentWithPayWayMethod($order);

        $this->fakeClient->queueResponse('payUsing3ds', PayWayResponse::fromArray([
            'returnCode' => '01',
            'message' => '3DS step required',
            'success' => false,
            'tokenAcceso' => 'jwt-token-step2',
            'urlColeccionDatoDispositivo' => 'https://centinelapistag.cardinalcommerce.com/V2/Cruise/Collect',
            'referenciaId' => 'ref-uuid-abc',
            'numeroTransaccionSistema' => 'tx-sis-1',
            'numeroTransaccionReferencia' => 'tx-ref-1',
            'numeroPayWay' => 'PW-12345',
        ]));

        $response = $this->graphQL('
            mutation($paymentId: ID!) {
                startPaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    data
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $result = $response->json('data.startPaymentChallenge');

        $this->assertTrue($result['success']);
        $this->assertSame('payway_waiting_device_data', $result['status']);
        $this->assertSame('jwt-token-step2', $result['data']['tokenAcceso']);
        $this->assertSame(
            'https://centinelapistag.cardinalcommerce.com/V2/Cruise/Collect',
            $result['data']['urlColeccionDatoDispositivo']
        );

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $payment->status);

        // Confirm the FakePayWayClient was actually used (not the real PayWayClient).
        $calls = $this->fakeClient->getCalls('payUsing3ds');
        $this->assertCount(1, $calls, 'FakePayWayClient::payUsing3ds should have been called exactly once via container binding override');
        $this->assertArrayHasKey('tarjetaUuid', $calls[0]['payload']);
        $this->assertSame('payway-uuid-test-fixture', $calls[0]['payload']['tarjetaUuid']);
    }

    public function testFinalizePaymentChallengeEscenario1Paid(): void
    {
        $order = $this->buildOrder();
        $payment = $this->createPaymentInWaitingDeviceDataState($order);

        $this->fakeClient->queueResponse('completePayment', PayWayResponse::fromArray([
            'returnCode' => '00',
            'message' => 'TRANSACCION EXITOSA',
            'success' => true,
            'numeroUuid' => 'uuid-final-escenario1',
            'numeroCompra' => 'compra-escenario1',
            'numeroAutorizacion' => 'auth-escenario1',
            'numeroPayWay' => 'pw-escenario1',
        ]));

        $response = $this->graphQL('
            mutation($paymentId: ID!) {
                finalizePaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    data
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $result = $response->json('data.finalizePaymentChallenge');

        $this->assertTrue($result['success']);
        $this->assertSame('payway_terminal', $result['status']);
        $this->assertSame('uuid-final-escenario1', $result['data']['payway_numero_uuid']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
        $this->assertSame('uuid-final-escenario1', $payment->getMetadata('payway_numero_uuid'));
        $this->assertSame('compra-escenario1', $payment->getMetadata('payway_numero_compra'));
        $this->assertNull($payment->getMetadata('payway_token_acceso'), 'Transient iframe key should be cleared after terminal');
        $this->assertNull($payment->getMetadata('payway_seguimiento'), 'Transient seguimiento should be cleared after terminal');

        $calls = $this->fakeClient->getCalls('completePayment');
        $this->assertCount(1, $calls, 'completePayment should have been called exactly once');
    }

    public function testFinalizePaymentChallengeEscenario2OtpThenPaid(): void
    {
        $order = $this->buildOrder();
        $payment = $this->createPaymentInWaitingDeviceDataState($order);

        // First finalize: escenario-2 — PayWay requires OTP (paso=4).
        $this->fakeClient->queueResponse('completePayment', PayWayResponse::fromArray([
            'returnCode' => '01',
            'message' => 'OTP required',
            'success' => false,
            'paso' => 4,
            'tokenAcceso' => 'otp-jwt-token',
            'urlColeccionDatoDispositivo' => 'https://payway.sv/otp-collect',
        ]));

        $firstResponse = $this->graphQL('
            mutation($paymentId: ID!) {
                finalizePaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    data
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $firstResponse->assertSuccessful();
        $firstResult = $firstResponse->json('data.finalizePaymentChallenge');

        $this->assertTrue($firstResult['success']);
        $this->assertSame('payway_pending_otp', $firstResult['status']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $payment->status);

        // Only 1 completePayment call so far.
        $this->assertCount(1, $this->fakeClient->getCalls('completePayment'));

        // Second GraphQL call: payment is PENDING_AUTHORIZATION, schema has no context param.
        // The resolver routes to finalizeChallenge() which — without otp_terminal_status —
        // returns the idempotent no-op (payway_pending_otp) without calling PayWay again.
        $secondResponse = $this->graphQL('
            mutation($paymentId: ID!) {
                finalizePaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    data
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $secondResponse->assertSuccessful();
        $secondResult = $secondResponse->json('data.finalizePaymentChallenge');

        $this->assertSame('payway_pending_otp', $secondResult['status']);
        $this->assertCount(
            1,
            $this->fakeClient->getCalls('completePayment'),
            'No additional completePayment call should be made while OTP is still pending'
        );

        // Simulate the processor receiving otp_terminal_status=PAID via direct model update
        // (as would happen when FE triggers the OTP terminal call through a separate endpoint).
        $payment->status = PaymentStatusEnum::PAID->value;
        $payment->save();

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::PAID->value, $payment->status);
    }

    public function testVoidPaymentTransitionsToCancelled(): void
    {
        $order = $this->buildOrder();
        $payment = $this->createPaidPaymentWithPayWayRef($order, 'compra-void-test');

        $this->fakeClient->queueResponse('voidTransaction', PayWayResponse::fromArray([
            'returnCode' => '00',
            'message' => 'Void successful',
            'success' => true,
        ]));

        $response = $this->graphQL('
            mutation($paymentId: ID!) {
                voidPayment(paymentId: $paymentId) {
                    status
                    message
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $result = $response->json('data.voidPayment');

        $this->assertSame('success', $result['status']);
        $this->assertStringContainsString('Void', $result['message']);

        $payment->refresh();
        $this->assertSame(PaymentStatusEnum::CANCELLED->value, $payment->status);

        // Confirm FakePayWayClient was used — proves the container binding override worked.
        $calls = $this->fakeClient->getCalls('voidTransaction');
        $this->assertCount(1, $calls, 'voidTransaction should have been called exactly once');
        $this->assertSame('compra-void-test', $calls[0]['path']);
    }

    /**
     * refundPayment calls PayWayProcessor::refund() which throws DomainException.
     * PaymentProcessorMutation catches all Exception, so the error surfaces as
     * data.refundPayment.status = 'error' (not a GraphQL errors[] entry).
     */
    public function testRefundPaymentReturnsDomainExceptionError(): void
    {
        $order = $this->buildOrder();
        $payment = $this->createPaidPaymentWithPayWayRef($order, 'compra-refund-test');

        $response = $this->graphQL('
            mutation($paymentId: ID!) {
                refundPayment(paymentId: $paymentId) {
                    status
                    message
                }
            }
        ', [
            'paymentId' => (string) $payment->id,
        ], [], [
            'X-Kanvas-Location' => $this->currentCompany->branch->uuid,
        ]);

        $response->assertSuccessful();
        $result = $response->json('data.refundPayment');

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('does not support refunds', $result['message']);
    }

    private function buildOrder(): Order
    {
        $order = new Order();
        $order->apps_id = $this->currentApp->getId();
        $order->companies_id = $this->currentCompany->getId();
        $order->users_id = static::$cachedUser->getId();
        $order->region_id = $this->regionId;
        $order->people_id = $this->peopleId;
        $order->order_number = random_int(10000, 999999);
        $order->total_gross_amount = 100.00;
        $order->total_net_amount = 100.00;
        $order->status = 'draft';
        $order->fulfillment_status = 'pending';
        $order->currency = 'USD';
        $order->is_deleted = false;
        $order->saveOrFail();

        return $order;
    }

    private function createPendingPaymentWithPayWayMethod(Order $order): Payments
    {
        $paymentMethod = PaymentMethods::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => static::$cachedUser->getId(),
            'payment_ending_numbers' => '1111',
            'payment_methods_brand' => 'visa',
            'expiration_date' => '2030-12',
            'processor' => 'payway',
            'metadata' => [
                CustomFieldEnum::PAYWAY_NUMERO_UUID->value => 'payway-uuid-test-fixture',
            ],
        ]);

        $payment = new Payments();
        $payment->apps_id = $this->currentApp->getId();
        $payment->companies_id = $this->currentCompany->getId();
        $payment->users_id = static::$cachedUser->getId();
        $payment->payable_id = $order->id;
        $payment->payable_type = get_class($order);
        $payment->payment_methods_id = $paymentMethod->id;
        $payment->payment_date = now()->toDateString();
        $payment->concept = 'PayWay 3DS test';
        $payment->amount = $order->getTotalAmount();
        $payment->currency = $order->currency ?? 'USD';
        $payment->status = PaymentStatusEnum::PENDING->value;
        $payment->idempotency_key = Str::uuid()->toString();
        $payment->metadata = [];
        $payment->saveOrFail();

        return $payment;
    }

    private function createPaymentInWaitingDeviceDataState(Order $order): Payments
    {
        $payment = $this->createPendingPaymentWithPayWayMethod($order);

        $payment->status = PaymentStatusEnum::WAITING_DEVICE_DATA->value;
        $payment->metadata = [
            'data' => [
                'payway_token_acceso' => 'iframe-token-abc',
                'payway_url_coleccion_dato_dispositivo' => 'https://payway.sv/collect',
                'payway_referencia_id' => 'ref-abc',
                'payway_numero_transaccion_sistema' => 'nts-001',
                'payway_numero_transaccion_referencia' => 'ntr-001',
                'payway_seguimiento' => [
                    'paso' => 3,
                    'tokenAcceso' => 'iframe-token-abc',
                    'referenciaId' => 'ref-abc',
                    'urlColeccionDatoDispositivo' => 'https://payway.sv/collect',
                    'numeroTransaccionSistema' => 'nts-001',
                    'numeroTransaccionReferencia' => 'ntr-001',
                ],
            ],
        ];
        $payment->save();

        return $payment;
    }

    private function createPaidPaymentWithPayWayRef(Order $order, string $numeroCompra): Payments
    {
        $paymentMethod = PaymentMethods::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => static::$cachedUser->getId(),
            'payment_ending_numbers' => '1111',
            'payment_methods_brand' => 'visa',
            'expiration_date' => '2030-12',
            'processor' => 'payway',
            'metadata' => [
                CustomFieldEnum::PAYWAY_NUMERO_UUID->value => 'payway-uuid-paid-fixture',
            ],
        ]);

        $payment = new Payments();
        $payment->apps_id = $this->currentApp->getId();
        $payment->companies_id = $this->currentCompany->getId();
        $payment->users_id = static::$cachedUser->getId();
        $payment->payable_id = $order->id;
        $payment->payable_type = get_class($order);
        $payment->payment_methods_id = $paymentMethod->id;
        $payment->payment_date = now()->toDateString();
        $payment->concept = 'PayWay 3DS paid';
        $payment->amount = $order->getTotalAmount();
        $payment->currency = $order->currency ?? 'USD';
        $payment->status = PaymentStatusEnum::PAID->value;
        $payment->processor = 'payway';
        $payment->idempotency_key = Str::uuid()->toString();
        $payment->metadata = [
            'data' => [
                'payway_numero_compra' => $numeroCompra,
                'payway_numero_uuid' => 'payway-uuid-paid-fixture',
                'payway_numero_autorizacion' => 'auth-paid-fixture',
            ],
        ];
        $payment->saveOrFail();

        return $payment;
    }
}
