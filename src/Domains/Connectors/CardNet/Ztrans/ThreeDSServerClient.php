<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Kanvas\Connectors\CardNet\Ztrans\Concerns\ReadsZtransSettings;
use Kanvas\Connectors\CardNet\Ztrans\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Ztrans\Exceptions\ZtransException;
use Kanvas\Connectors\CardNet\Ztrans\Support\ThreeDSSignature;

final class ThreeDSServerClient
{
    use ReadsZtransSettings;

    private const string DEFAULT_BASE_URL = 'https://labservicios.cardnet.com.do/servicios/3ds/server';

    public function __construct(AppInterface $app)
    {
        $this->app = $app;
    }

    public function authenticate(array $payload): array
    {
        $integratorCode = $this->setting(ConfigurationEnum::THREE_DS_INTEGRATOR_CODE);

        return $this->post('/authentication', [
            ...$payload,
            'integratorCode' => $integratorCode,
            'signature' => ThreeDSSignature::forAuthentication(
                integratorCode: $integratorCode,
                cardExpiryDate: (string) $payload['cardExpiryDate'],
                integratorTxId: (string) $payload['integratorTxId'],
                purchaseAmount: (string) $payload['purchaseAmount'],
                apiKey: $this->setting(ConfigurationEnum::THREE_DS_API_KEY)
            ),
        ]);
    }

    public function status(string $threeDSServerTransId, string $integratorTxId, string $purchaseAmount): array
    {
        $integratorCode = $this->setting(ConfigurationEnum::THREE_DS_INTEGRATOR_CODE);

        return $this->post('/status', [
            'integratorCode' => $integratorCode,
            'threeDSServerTransID' => $threeDSServerTransId,
            'signature' => ThreeDSSignature::forStatus(
                integratorCode: $integratorCode,
                integratorTxId: $integratorTxId,
                purchaseAmount: $purchaseAmount,
                apiKey: $this->setting(ConfigurationEnum::THREE_DS_API_KEY)
            ),
        ]);
    }

    private function post(string $path, array $body): array
    {
        $baseUrl = rtrim($this->setting(ConfigurationEnum::THREE_DS_BASE_URL, self::DEFAULT_BASE_URL), '/');

        try {
            $response = Http::timeout(30)->acceptJson()->asJson()->post($baseUrl . $path, $body);
        } catch (ConnectionException $e) {
            throw new ZtransException('CardNet 3DS server is unreachable: ' . $e->getMessage());
        }

        $decoded = $response->json();

        if (! is_array($decoded)) {
            throw new ZtransException('CardNet 3DS server returned an unreadable response (HTTP ' . $response->status() . ').');
        }

        return $decoded;
    }
}
