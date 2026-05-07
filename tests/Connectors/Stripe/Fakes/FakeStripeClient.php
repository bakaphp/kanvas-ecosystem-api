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

    public function __construct(?string $apiKey = 'sk_test_fake')
    {
        parent::__construct(['api_key' => $apiKey]);
        $this->paymentIntentsService = new FakePaymentIntentsService();
        $this->refundsService = new FakeRefundsService();
        $this->customersService = new FakeCustomersService();
        $this->paymentMethodsService = new FakePaymentMethodsService();
        $this->webhooksService = new FakeWebhooks();
    }

    public function __get($name): mixed
    {
        return match ($name) {
            'paymentIntents' => $this->paymentIntentsService,
            'refunds' => $this->refundsService,
            'customers' => $this->customersService,
            'paymentMethods' => $this->paymentMethodsService,
            'webhooks' => $this->webhooksService,
            default => parent::__get($name),
        };
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
