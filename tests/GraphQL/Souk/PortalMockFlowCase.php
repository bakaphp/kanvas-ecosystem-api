<?php

declare(strict_types=1);

namespace Tests\GraphQL\Souk;

use ArrayObject;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Connectors\EchoPay\DataTransferObject\ConsumerAuthentication;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Services\EchoPayService;
use Kanvas\Souk\Payments\Enums\PaymentStatusEnum;
use Kanvas\Souk\Payments\Infrastructure\Processors\Portal\PortalProcessor;
use Mockery;
use Mockery\MockInterface;
use Throwable;

abstract class PortalMockFlowCase extends OrderBase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'commerce', 'crm', 'ecosystem'];

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

    protected function createAuthorizedPortalPayment(MockInterface $service): string
    {
        $this->bindPortalProcessor($service);
        [$paymentId] = $this->createPortalPaymentOnFreshOrder();
        $this->startChallenge($paymentId);
        $finalize = $this->finalizeChallenge($paymentId);
        $this->assertSame(PaymentStatusEnum::AUTHORIZED->value, $finalize['status'], $finalize['message']);

        return $paymentId;
    }

    protected function runPaymentAction(
        string $mutation,
        string $paymentId,
        array $extraArguments = [],
        string $selection = 'status message payment { id status }'
    ): array {
        $amountVariable = isset($extraArguments['amount']) ? ', $amount: Float' : '';
        $amountArgument = isset($extraArguments['amount']) ? ', amount: $amount' : '';

        return $this->graphQLData($this->graphQL(
            'mutation ' . $mutation . '($paymentId: ID!' . $amountVariable . ') {
                ' . $mutation . '(paymentId: $paymentId' . $amountArgument . ') {
                    ' . $selection . '
                }
            }',
            ['paymentId' => $paymentId, ...$extraArguments],
            [],
            ['X-Kanvas-Location' => $this->company->branch->uuid],
        ), $mutation);
    }

    protected function createPortalPaymentOnFreshOrder(): array
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

    protected function startChallenge(string $paymentId): array
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

    protected function finalizeChallenge(string $paymentId): array
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

    protected function portalServiceWithRealMockClient(): MockInterface
    {
        return Mockery::mock(EchoPayService::class, [$this->apps, $this->company])->makePartial();
    }

    protected function bindPortalProcessor(EchoPayService $service): void
    {
        $this->app->bind('payment_processor.portal', fn ($app, array $params) => new PortalProcessor(
            app: $params['app'],
            company: $params['company'],
            service: $service,
        ));
    }

    protected function collectReportedExceptions(): ArrayObject
    {
        $reported = new ArrayObject();
        $handler = Mockery::mock(ExceptionHandler::class)->shouldIgnoreMissing();
        $handler->shouldReceive('report')->andReturnUsing(function (Throwable $e) use ($reported): void {
            $reported->append($e);
        });
        $this->app->instance(ExceptionHandler::class, $handler);

        return $reported;
    }

    protected function consumerAuthentication(array $overrides = []): ConsumerAuthentication
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
