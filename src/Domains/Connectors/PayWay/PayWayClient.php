<?php

declare(strict_types=1);

namespace Kanvas\Connectors\PayWay;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\PayWay\Contracts\PayWayClientInterface;
use Kanvas\Connectors\PayWay\DataTransferObject\PayWayResponse;
use Kanvas\Connectors\PayWay\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

final class PayWayClient implements PayWayClientInterface
{
    private const string PATH_USING_3DS = '/web-payway-sv/paywayone/api/rest/payments/using3ds';
    private const string PATH_PAYMENT = '/web-payway-sv/paywayone/api/rest/payments/';
    private const string PATH_TRACE = '/web-payway-sv/paywayone/api/rest/payments/trace/';
    private const string PATH_VOID = '/web-payway-sv/paywayone/api/rest/payments/void/';

    private GuzzleClient $http;
    private string $token;
    private int $colectorId;
    private string $usuarioOperacion;

    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        ?GuzzleClient $http = null,
    ) {
        $this->token = $this->resolveSetting(ConfigurationEnum::PAYWAY_TOKEN);
        $colectorIdRaw = $this->resolveSetting(ConfigurationEnum::PAYWAY_COLECTOR_ID);
        $this->usuarioOperacion = $this->resolveSetting(ConfigurationEnum::PAYWAY_USUARIO_OPERACION);
        $baseUrl = $this->resolveSetting(ConfigurationEnum::PAYWAY_BASE_URL) ?: 'https://test.payway.sv';

        if ($this->token === '' || $colectorIdRaw === '' || $this->usuarioOperacion === '') {
            throw new ValidationException('PayWay is not fully configured (token/colectorId/usuarioOperacion missing — checked company then app).');
        }

        $this->colectorId = (int) $colectorIdRaw;

        $this->http = $http ?? new GuzzleClient([
            'base_uri' => $baseUrl,
            'timeout' => 30.0,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Resolve a PayWay setting company-first, then fall back to app-level.
     * Per-merchant credentials live on the company; an app-level value acts as
     * a shared default for apps that run a single PayWay collector.
     */
    private function resolveSetting(ConfigurationEnum $key): string
    {
        $companyValue = (string) $this->company->get($key->value);

        return $companyValue !== '' ? $companyValue : (string) $this->app->get($key->value);
    }

    public function payUsing3ds(array $payload): PayWayResponse
    {
        return $this->postJson(self::PATH_USING_3DS, $payload);
    }

    public function completePayment(array $payload): PayWayResponse
    {
        // Per PDF §4.3 (Step 3), the complete-payment call reuses the same
        // /using3ds path but with paso=3 or paso=5 inside datos3ds.
        return $this->postJson(self::PATH_USING_3DS, $payload);
    }

    public function getTransaction(string $numeroCompra): PayWayResponse
    {
        if (! preg_match('/^\d+$/', $numeroCompra)) {
            throw new ValidationException('Invalid numeroCompra: must be numeric.');
        }

        return $this->postJson(self::PATH_PAYMENT . $numeroCompra, []);
    }

    public function traceTransaction(string $traceId): PayWayResponse
    {
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $traceId)) {
            throw new ValidationException('Invalid traceId: must be a UUID v4.');
        }

        return $this->postJson(self::PATH_TRACE . $traceId, []);
    }

    public function voidTransaction(string $numeroCompra, string $traceId): PayWayResponse
    {
        if (! preg_match('/^\d+$/', $numeroCompra)) {
            throw new ValidationException('Invalid numeroCompra: must be numeric.');
        }

        return $this->postJson(self::PATH_VOID . $numeroCompra, ['traceId' => $traceId]);
    }

    private function postJson(string $path, array $body): PayWayResponse
    {
        $requestBody = $this->injectAuth($body);

        try {
            $response = $this->http->post($path, ['json' => $requestBody]);
            $decoded = json_decode((string) $response->getBody(), true) ?? [];

            return PayWayResponse::fromArray($decoded);
        } catch (GuzzleException $e) {
            return PayWayResponse::networkFailure($e->getMessage());
        }
    }

    private function injectAuth(array $body): array
    {
        return array_merge($body, [
            'token' => $this->token,
            'usuarioOperacion' => $this->usuarioOperacion,
            'colectorId' => $this->colectorId,
        ]);
    }
}
