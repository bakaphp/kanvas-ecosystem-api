<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\CardNet\Ztrans\Concerns\ReadsZtransSettings;
use Kanvas\Connectors\CardNet\Ztrans\DataTransferObject\ZtransResponse;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Ztrans\Exceptions\ZtransException;
use Kanvas\Connectors\CardNet\Ztrans\Exceptions\ZtransUnknownOutcomeException;

final class ZtransClient
{
    use ReadsZtransSettings;

    private const string DEFAULT_BASE_URL = 'https://labservicios.cardnet.com.do/api/payment';

    public function __construct(AppInterface $app)
    {
        $this->app = $app;
    }

    public function createIdempotencyKey(): string
    {
        try {
            $response = $this->request()->withHeaders(['Accept' => 'text/plain'])->post('/idenpotency-keys');
        } catch (ConnectionException $e) {
            throw new ZtransException('CardNet Ztrans is unreachable: ' . $e->getMessage());
        }

        $key = trim(str_replace('ikey:', '', trim($response->body())));

        if (! $response->successful() || $key === '') {
            throw new ZtransException('CardNet Ztrans did not return an idempotency key.');
        }

        return $key;
    }

    public function sale(array $payload): ZtransResponse
    {
        return $this->transact('/transactions/sales', $payload);
    }

    public function checkin(array $payload): ZtransResponse
    {
        return $this->transact('/transactions/checkins', $payload);
    }

    public function checkout(array $payload): ZtransResponse
    {
        return $this->transact('/transactions/checkouts', $payload);
    }

    public function void(array $payload): ZtransResponse
    {
        return $this->transact('/transactions/voids', $payload);
    }

    public function refund(array $payload): ZtransResponse
    {
        return $this->transact(
            '/transactions/refund',
            [...$payload, 'Operation' => 'refund'],
            $this->setting(ConfigurationEnum::REFUND_BEARER_TOKEN)
        );
    }

    private function transact(string $path, array $payload, ?string $bearerToken = null): ZtransResponse
    {
        $body = [
            ...$payload,
            'merchant-id' => $this->setting(ConfigurationEnum::MERCHANT_ID),
            'terminal-id' => $this->setting(ConfigurationEnum::TERMINAL_ID),
        ];

        $request = $this->request();

        if ($bearerToken !== null) {
            $request = $request->withToken($bearerToken);
        }

        try {
            $response = $request->asJson()->post($path, $body);
        } catch (ConnectionException $e) {
            throw new ZtransUnknownOutcomeException('CardNet Ztrans did not answer: ' . $e->getMessage());
        }

        $decoded = $response->json();

        if (! is_array($decoded) && $response->clientError()) {
            throw new ZtransException('CardNet Ztrans rejected the request (HTTP ' . $response->status() . ').');
        }

        if (! is_array($decoded)) {
            throw new ZtransUnknownOutcomeException('CardNet Ztrans returned an unreadable response (HTTP ' . $response->status() . ').');
        }

        return ZtransResponse::fromArray($decoded);
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->setting(ConfigurationEnum::BASE_URL, self::DEFAULT_BASE_URL), '/'))
            ->timeout(30)
            ->acceptJson();
    }
}
