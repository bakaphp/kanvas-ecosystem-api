<?php

declare(strict_types=1);

namespace Tests\Connectors\PayWay\Fakes;

use Kanvas\Connectors\PayWay\Contracts\PayWayClientInterface;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use LogicException;
use Throwable;

final class FakePayWayClient implements PayWayClientInterface
{
    /** @var array<string, list<PayWayResponse|Throwable>> */
    private array $responses = [];

    /** @var array<string, list<array{path: string, payload: array}>> */
    private array $calls = [];

    public function queueResponse(string $method, PayWayResponse|Throwable $response): void
    {
        $this->responses[$method][] = $response;
    }

    /** @return list<array{path: string, payload: array}> */
    public function getCalls(string $method): array
    {
        return $this->calls[$method] ?? [];
    }

    public function payUsing3ds(array $payload): PayWayResponse
    {
        return $this->recordAndShift('payUsing3ds', '', $payload);
    }

    public function completePayment(array $payload): PayWayResponse
    {
        return $this->recordAndShift('completePayment', '', $payload);
    }

    public function getTransaction(string $numeroCompra): PayWayResponse
    {
        return $this->recordAndShift('getTransaction', $numeroCompra, []);
    }

    public function traceTransaction(string $traceId): PayWayResponse
    {
        return $this->recordAndShift('traceTransaction', $traceId, []);
    }

    public function voidTransaction(string $numeroCompra, string $traceId): PayWayResponse
    {
        return $this->recordAndShift('voidTransaction', $numeroCompra, ['traceId' => $traceId]);
    }

    private function recordAndShift(string $method, string $path, array $payload): PayWayResponse
    {
        $this->calls[$method][] = ['path' => $path, 'payload' => $payload];

        if (empty($this->responses[$method])) {
            throw new LogicException(self::class . "::{$method} called without a queued response.");
        }

        $response = array_shift($this->responses[$method]);

        if ($response instanceof Throwable) {
            throw $response;
        }

        return $response;
    }
}
