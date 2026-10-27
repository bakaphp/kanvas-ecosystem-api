<?php

declare(strict_types=1);

namespace Tests\Connectors\Stripe\Fakes;

use LogicException;
use Stripe\StripeClient;
use Throwable;

/**
 * Hand-rolled test double for StripeClient.
 *
 * Bind into the container before the test:
 *   $this->app->bind(StripeClient::class, fn () => new FakeStripeClient());
 * Or, once Phase 7 lands, swap the named binding:
 *   $this->app->bind('payment_processor.stripe', fn () => new FakeStripeClient());
 *
 * In v17, StripeClient exposes all services via __get → CoreServiceFactory::getService.
 * We override __get here to intercept the 5 resource names before they reach the factory.
 */
final class FakeStripeClient extends StripeClient
{
    private FakePaymentIntentsService $paymentIntentsService;
    private FakeRefundsService $refundsService;
    private FakeCustomersService $customersService;
    private FakePaymentMethodsService $paymentMethodsService;
    private FakeWebhooks $webhooksService;
    private FakeCouponsService $couponsService;
    private FakeWebhookEndpointsService $webhookEndpointsService;
    private FakePaymentLinksService $paymentLinksService;
    private FakeCheckoutService $checkoutService;
    private FakeTaxService $taxService;
    private FakeAccountsService $accountsService;

    public function __construct(?string $apiKey = 'sk_test_fake')
    {
        parent::__construct(['api_key' => $apiKey]);
        $this->paymentIntentsService = new FakePaymentIntentsService();
        $this->refundsService = new FakeRefundsService();
        $this->customersService = new FakeCustomersService();
        $this->paymentMethodsService = new FakePaymentMethodsService();
        $this->webhooksService = new FakeWebhooks();
        $this->couponsService = new FakeCouponsService();
        $this->webhookEndpointsService = new FakeWebhookEndpointsService();
        $this->paymentLinksService = new FakePaymentLinksService();
        $this->checkoutService = new FakeCheckoutService();
        $this->taxService = new FakeTaxService();
        $this->accountsService = new FakeAccountsService();
    }

    public function __get($name): mixed
    {
        return match ($name) {
            'paymentIntents' => $this->paymentIntentsService,
            'refunds' => $this->refundsService,
            'customers' => $this->customersService,
            'paymentMethods' => $this->paymentMethodsService,
            'webhooks' => $this->webhooksService,
            'coupons' => $this->couponsService,
            'webhookEndpoints' => $this->webhookEndpointsService,
            'paymentLinks' => $this->paymentLinksService,
            'checkout' => $this->checkoutService,
            'tax' => $this->taxService,
            'accounts' => $this->accountsService,
            default => parent::__get($name),
        };
    }

    public function getWebhookEndpoints(): FakeWebhookEndpointsService
    {
        return $this->webhookEndpointsService;
    }

    public function getTax(): FakeTaxService
    {
        return $this->taxService;
    }

    public function getAccounts(): FakeAccountsService
    {
        return $this->accountsService;
    }

    public function getPaymentLinks(): FakePaymentLinksService
    {
        return $this->paymentLinksService;
    }

    public function getCoupons(): FakeCouponsService
    {
        return $this->couponsService;
    }

    public function getPaymentIntents(): FakePaymentIntentsService
    {
        return $this->paymentIntentsService;
    }

    public function getRefunds(): FakeRefundsService
    {
        return $this->refundsService;
    }

    public function getCustomers(): FakeCustomersService
    {
        return $this->customersService;
    }

    public function getPaymentMethods(): FakePaymentMethodsService
    {
        return $this->paymentMethodsService;
    }

    public function getWebhooks(): FakeWebhooks
    {
        return $this->webhooksService;
    }

    public function getCheckout(): FakeCheckoutService
    {
        return $this->checkoutService;
    }
}

/**
 * Base trait providing the scripted-response queue and call recorder
 * shared by all fake service classes.
 */
trait FakeServiceTrait
{
    /** @var array<string, list<mixed>> */
    private array $responses = [];

    /** @var array<string, list<array{params: mixed, options: mixed}>> */
    private array $calls = [];

    public function queueResponse(string $method, mixed $response): void
    {
        $this->responses[$method][] = $response;
    }

    /** @return list<array{params: mixed, options: mixed}> */
    public function getCalls(string $method): array
    {
        return $this->calls[$method] ?? [];
    }

    private function recordAndShift(string $method, mixed $params, mixed $options = null): mixed
    {
        $this->calls[$method][] = ['params' => $params, 'options' => $options];

        if (empty($this->responses[$method])) {
            throw new LogicException(static::class . '::' . $method . ' called without a queued response.');
        }

        $response = array_shift($this->responses[$method]);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}

final class FakePaymentIntentsService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }

    public function retrieve(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('retrieve', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function capture(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('capture', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function cancel(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('cancel', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function confirm(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('confirm', array_merge(['id' => $id], $params ?? []), $options);
    }
}

final class FakeRefundsService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }
}

final class FakeCouponsService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }
}

final class FakeWebhookEndpointsService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }
}

final class FakePaymentLinksService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }

    public function retrieve(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('retrieve', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function update(string $id, array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('update', array_merge(['id' => $id], $params), $options);
    }
}

final class FakeCustomersService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }

    public function retrieve(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('retrieve', array_merge(['id' => $id], $params ?? []), $options);
    }
}

final class FakePaymentMethodsService
{
    use FakeServiceTrait;

    public function retrieve(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('retrieve', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function attach(string $id, array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('attach', array_merge(['id' => $id], $params), $options);
    }

    public function detach(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('detach', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function update(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('update', array_merge(['id' => $id], $params ?? []), $options);
    }
}

/**
 * Fake for Stripe\Webhook static utility.
 * In production code: Webhook::constructEvent($payload, $sig, $secret)
 * In tests: $client->webhooks->constructEvent($payload, $sig, $secret)
 * Phase 9 webhook job should call this via the injected client instance,
 * not via Webhook::constructEvent directly, so the fake can intercept it.
 */
final class FakeWebhooks
{
    use FakeServiceTrait;

    public function constructEvent(string $payload, string $sigHeader, string $secret, int $tolerance = 300): mixed
    {
        return $this->recordAndShift(
            'constructEvent',
            ['payload' => $payload, 'sig_header' => $sigHeader, 'secret' => $secret, 'tolerance' => $tolerance],
        );
    }
}

/**
 * Fake for $client->checkout — a thin holder that exposes ->sessions.
 * Mirrors the real Stripe\Service\Checkout\CheckoutServiceFactory shape.
 */
final class FakeCheckoutService
{
    public readonly FakeCheckoutSessionsService $sessions;

    public function __construct()
    {
        $this->sessions = new FakeCheckoutSessionsService();
    }
}

final class FakeCheckoutSessionsService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }

    public function retrieve(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('retrieve', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function expire(string $id, ?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('expire', array_merge(['id' => $id], $params ?? []), $options);
    }

    public function update(string $id, array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('update', array_merge(['id' => $id], $params), $options);
    }
}

final class FakeTaxCalculationsService
{
    use FakeServiceTrait;

    public function create(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('create', $params, $options);
    }
}

final class FakeTaxTransactionsService
{
    use FakeServiceTrait;

    public function createFromCalculation(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('createFromCalculation', $params, $options);
    }

    public function createReversal(array $params, ?array $options = null): mixed
    {
        return $this->recordAndShift('createReversal', $params, $options);
    }
}

final class FakeTaxService
{
    public readonly FakeTaxCalculationsService $calculations;
    public readonly FakeTaxTransactionsService $transactions;

    public function __construct()
    {
        $this->calculations = new FakeTaxCalculationsService();
        $this->transactions = new FakeTaxTransactionsService();
    }
}

final class FakeAccountsService
{
    use FakeServiceTrait;

    public function retrieve(?array $params = null, ?array $options = null): mixed
    {
        return $this->recordAndShift('retrieve', $params ?? [], $options);
    }
}
