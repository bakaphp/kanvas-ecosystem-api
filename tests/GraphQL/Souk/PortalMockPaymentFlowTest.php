<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Enums\PaymentStatusEnum as EchoPayStatusEnum;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Kanvas\Souk\Payments\Models\Payments;
use Mockery;
use Mockery\MockInterface;

class PortalMockPaymentFlowTest extends OrderBase
{
    protected string $variantId;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps->set(ConfigurationEnum::USE_MOCK->value, true);
        $this->apps->set('ECHO_PAY_MERCHANT_ID', 'mock-merchant');
        $this->apps->set('ECHO_PAY_MERCHANT_KEY', 'mock-key');
        $this->apps->set('ECHO_PAY_MERCHANT_SECRET', 'mock-secret');
        $this->apps->set(ConfigurationEnum::REDIRECT_URL->value, 'https://test.local/3ds/return');

        $productResponse = $this->createProduct(attributes: [
            ['name' => 'slots', 'value' => 100],
        ])->json()['data']['createProduct'];

        $variantResponse = $this->createVariant(
            productId: $productResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
            attributes: [['name' => 'timezone', 'value' => 'America/New_York']],
        )->json()['data']['createVariant'];

        $this->addVariantToChannel(
            variantId: $variantResponse['id'],
            channelId: $this->channelResponse['id'],
            warehouseData: ['id' => $this->warehouseResponse['id']],
        );

        $this->addVariantToWarehouse(
            variantId: $variantResponse['id'],
            warehouseId: $this->warehouseResponse['id'],
            amount: 100,
        );

        $this->variantId = $variantResponse['id'];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testPortalMock3DSFlowEndToEnd(): void
    {
        $paymentMethod = $this->addPaymentMethod($this->company, $this->getCardData());
        $this->assertArrayHasKey('id', $paymentMethod);

        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $addPayment = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    payment { id status }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_methods_id' => $paymentMethod['id'],
                'payment_method' => 'CARD',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $addPayment->assertSuccessful();
        $this->assertSame('success', $addPayment->json('data.addPaymentToOrder.status'));
        $paymentId = $addPayment->json('data.addPaymentToOrder.payment.id');
        $this->assertNotEmpty($paymentId);

        $start = $this->graphQL('
            mutation startPaymentChallenge($paymentId: ID!) {
                startPaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    message
                }
            }
        ', ['paymentId' => $paymentId], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $start->assertSuccessful();
        $this->assertTrue($start->json('data.startPaymentChallenge.success'), 'startPaymentChallenge failed: ' . $start->json('data.startPaymentChallenge.message'));
        $this->assertSame(
            PaymentStatusEnum::WAITING_DEVICE_DATA->value,
            $start->json('data.startPaymentChallenge.status')
        );
        $this->assertSame(
            PaymentStatusEnum::WAITING_DEVICE_DATA->value,
            Payments::find($paymentId)->status
        );

        $finalize = $this->graphQL('
            mutation finalizePaymentChallenge($paymentId: ID!) {
                finalizePaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    message
                }
            }
        ', ['paymentId' => $paymentId], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $finalize->assertSuccessful();
        $this->assertTrue($finalize->json('data.finalizePaymentChallenge.success'), 'finalizePaymentChallenge failed: ' . $finalize->json('data.finalizePaymentChallenge.message'));
        $this->assertSame(
            PaymentStatusEnum::AUTHORIZED->value,
            $finalize->json('data.finalizePaymentChallenge.status')
        );
        $this->assertSame(
            PaymentStatusEnum::AUTHORIZED->value,
            Payments::find($paymentId)->status
        );

        $capture = $this->graphQL('
            mutation capturePayment($paymentId: ID!) {
                capturePayment(paymentId: $paymentId) {
                    status
                    message
                    payment { id status }
                }
            }
        ', ['paymentId' => $paymentId], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $capture->assertSuccessful();
        $this->assertSame('success', $capture->json('data.capturePayment.status'), 'capturePayment failed: ' . $capture->json('data.capturePayment.message'));
        $this->assertSame(
            PaymentStatusEnum::PAID->value,
            $capture->json('data.capturePayment.payment.status')
        );
        $this->assertSame(
            PaymentStatusEnum::PAID->value,
            Payments::find($paymentId)->status
        );
    }

    public function testStartPaymentChallengeExposesTheLegacyAndStandardDeviceDataKeys(): void
    {
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();

        $start = $this->startChallenge($paymentId);

        $this->assertTrue($start['success'], $start['message']);
        $this->assertSame(PaymentStatusEnum::WAITING_DEVICE_DATA->value, $start['status']);
        $this->assertSame('https://mock.echopay.local/3ds/collect', $start['data']['deviceDataUrl']);
        $this->assertSame('mock-access-token', $start['data']['accessToken']);
        $this->assertNotEmpty($start['data']['referenceId']);
        $this->assertSame($start['data']['deviceDataUrl'], $start['data']['device_data_collection_url']);
        $this->assertSame($start['data']['accessToken'], $start['data']['access_token']);
        $this->assertSame($start['data']['referenceId'], $start['data']['reference_id']);
        $this->assertNotEmpty($start['data']['transaction_id']);
    }

    public function testFrictionlessCardIsAuthorizedByFinalizePaymentChallenge(): void
    {
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertTrue($finalize['success'], $finalize['message']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $finalize['status']);
        $this->assertNotEmpty($finalize['data']['intent_id']);
        $this->assertNotEmpty($finalize['data']['transaction_id']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, Payments::find($paymentId)->status);
    }

    public function testChallengeRequiredReturnsTheAcsKeysAndFinalizeAuthorizesAfterTheWebhook(): void
    {
        [$paymentId, $orderId] = $this->createPortalPaymentOnFreshOrder();
        $validatedWith = null;
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthentication([
                'acsUrl' => 'https://acs.test/challenge',
                'stepUpUrl' => 'https://acs.test/step-up',
                'pareq' => 'pareq-payload',
                'accessToken' => 'step-token',
                'authenticationTransactionId' => 'auth-tx-1',
            ]),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::PENDING_AUTHENTICATION->value,
        ]);
        $service->shouldReceive('validatePayerAuthResult')->once()->withArgs(function (string $transactionId) use (&$validatedWith): bool {
            $validatedWith = $transactionId;

            return true;
        })->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthentication(['eci' => '05', 'eciRaw' => '05']),
            'id' => 'validate-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_SUCCESSFUL->value,
        ]);
        $this->bindPortalProcessor($service);
        $this->startChallenge($paymentId);

        $challenge = $this->finalizeChallenge($paymentId);

        $this->assertTrue($challenge['success'], $challenge['message']);
        $this->assertSame(PaymentStatusEnum::PENDING_AUTHORIZATION->value, $challenge['status']);
        $this->assertSame('https://acs.test/challenge', $challenge['data']['acsUrl']);
        $this->assertSame('https://acs.test/step-up', $challenge['data']['stepUpUrl']);
        $this->assertSame('pareq-payload', $challenge['data']['pareq']);
        $this->assertSame('step-token', $challenge['data']['accessToken']);
        $this->assertSame('auth-tx-1', $challenge['data']['authenticationTransactionId']);
        $this->assertSame('https://test.local/3ds/return', $challenge['data']['returnUrl']);
        $this->assertSame($challenge['data']['acsUrl'], $challenge['data']['acs_url']);
        $this->assertSame('auth-tx-1', Order::find($orderId)->get('auth_transaction_id'));

        Order::find($orderId)->set('auth_transaction_id', 'webhook-tx');
        $authorized = $this->finalizeChallenge($paymentId);

        $this->assertTrue($authorized['success'], $authorized['message']);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $authorized['status']);
        $this->assertSame('webhook-tx', $validatedWith);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, Payments::find($paymentId)->status);
    }

    public function testFailedEnrollmentFailsThePaymentThroughFinalizePaymentChallenge(): void
    {
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $service = $this->portalServiceWithRealMockClient();
        $service->shouldReceive('checkPayerEnrollment')->once()->andReturn([
            'consumerAuthenticationInformation' => $this->consumerAuthentication(),
            'id' => 'enroll-1',
            'status' => EchoPayStatusEnum::AUTHENTICATION_FAILED->value,
        ]);
        $service->shouldNotReceive('authorizePayment');
        $this->bindPortalProcessor($service);
        $this->startChallenge($paymentId);

        $finalize = $this->finalizeChallenge($paymentId);

        $this->assertFalse($finalize['success']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, $finalize['status']);
        $this->assertSame(PaymentStatusEnum::FAILED->value, Payments::find($paymentId)->status);
    }

    public function testStartPaymentChallengeWithCardRejectsPortalWithTheTokenizationInstructions(): void
    {
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $response = $this->graphQL('
            mutation startPaymentChallengeWithCard($orderId: ID!, $paymentData: StartChallengeCardInput!) {
                startPaymentChallengeWithCard(orderId: $orderId, paymentData: $paymentData) {
                    success
                    status
                    message
                }
            }
        ', [
            'orderId' => $order->id,
            'paymentData' => [
                'number' => '4111 1111 1111 1111',
                'cvv' => '123',
                'processor' => 'portal',
                'expiration_date' => '2030-12',
                'firstname' => 'Juan',
                'lastname' => 'Perez',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $response->assertSuccessful();
        $this->assertFalse($response->json('data.startPaymentChallengeWithCard.success'));
        $this->assertSame(PaymentStatusEnum::FAILED->value, $response->json('data.startPaymentChallengeWithCard.status'));
        $this->assertStringContainsString('createPaymentMethod', $response->json('data.startPaymentChallengeWithCard.message'));
    }

    private function createPortalPaymentOnFreshOrder(): array
    {
        $paymentMethod = $this->addPaymentMethod($this->company, $this->getCardData());
        $order = $this->createOrderFromCart(
            variantId: $this->variantId,
            quantity: 1,
            metadata: ['data' => []],
        );

        $addPayment = $this->graphQL('
            mutation addPaymentToOrder($orderID: ID!, $input: PaymentInput!) {
                addPaymentToOrder(orderID: $orderID, input: $input) {
                    status
                    payment { id }
                }
            }
        ', [
            'orderID' => $order->id,
            'input' => [
                'payment_methods_id' => $paymentMethod['id'],
                'payment_method' => 'CARD',
            ],
        ], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ]);

        $this->assertSame('success', $addPayment->json('data.addPaymentToOrder.status'));

        return [$addPayment->json('data.addPaymentToOrder.payment.id'), $order->id];
    }

    private function startChallenge(string $paymentId): array
    {
        return $this->graphQL('
            mutation startPaymentChallenge($paymentId: ID!) {
                startPaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    message
                    data
                }
            }
        ', ['paymentId' => $paymentId], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ])->json('data.startPaymentChallenge');
    }

    private function finalizeChallenge(string $paymentId): array
    {
        return $this->graphQL('
            mutation finalizePaymentChallenge($paymentId: ID!) {
                finalizePaymentChallenge(paymentId: $paymentId) {
                    success
                    status
                    message
                    data
                }
            }
        ', ['paymentId' => $paymentId], [], [
            'X-Kanvas-Location' => $this->company->branch->uuid,
        ])->json('data.finalizePaymentChallenge');
    }

    private function portalServiceWithRealMockClient(): MockInterface
    {
        return Mockery::mock(EchoPayService::class, [$this->apps, $this->company])->makePartial();
    }

    private function bindPortalProcessor(EchoPayService $service): void
    {
        $this->app->bind('payment_processor.portal', fn ($app, array $params) => new PortalProcessor(
            app: $params['app'],
            company: $params['company'],
            service: $service,
        ));
    }

    private function consumerAuthentication(array $overrides = []): ConsumerAuthentication
    {
        return ConsumerAuthentication::from([
            'indicator' => null,
            'authenticationTransactionId' => null,
            'eciRaw' => null,
            'authenticationResult' => null,
            'strongAuthentication' => [],
            'authenticationStatusMsg' => null,
            'eci' => null,
            'token' => '',
            'accessToken' => null,
            'cavv' => null,
            'paresStatus' => null,
            'xid' => null,
            'directoryServerTransactionId' => null,
            'threeDSServerTransactionId' => null,
            'specificationVersion' => null,
            'acsTransactionId' => null,
            'ucafCollectionIndicator' => null,
            'ucafAuthenticationData' => null,
            'challengeRequired' => null,
            'acsUrl' => null,
            'acsReferenceNumber' => null,
            'stepUpUrl' => null,
            'pareq' => null,
            'veresEnrolled' => null,
            'acsOperatorID' => null,
            ...$overrides,
        ]);
    }
}
