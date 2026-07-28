<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
use Kanvas\Connectors\Azul\Services\AzulCertificate;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $auth1;
    protected string $auth2;
    protected GuzzleClient $client;
    protected bool $debugLog;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::AZUL_BASE_URL->value)
            ?? $config['base_url']
            ?? ConfigurationEnum::SANDBOX_URL->value;

        $this->auth1 = $this->app->get(ConfigurationEnum::AZUL_AUTH1->value)
            ?? $config['auth1']
            ?? '';
        $this->auth2 = $this->app->get(ConfigurationEnum::AZUL_AUTH2->value)
            ?? $config['auth2']
            ?? '';

        $this->debugLog = (bool) ($this->app->get(ConfigurationEnum::AZUL_DEBUG_LOG->value)
            ?? $config['debug_log']
            ?? false);

        if (empty($this->auth1) || empty($this->auth2)) {
            throw new ValidationException('Azul configuration is missing: Auth1 and Auth2 are required');
        }

        $guzzleConfig = array_merge([
            'headers' => [
                'Content-Type' => 'application/json',
                'Auth1'        => $this->auth1,
                'Auth2'        => $this->auth2,
            ],
            'timeout'         => 120,
            'connect_timeout' => 15,
        ], AzulCertificate::fromApp($this->app, $config)->guzzleOptions());

        $this->client = new GuzzleClient($guzzleConfig);
    }

    public function post(array $data, ?string $url = null): array
    {
        $endpoint = $url ?? $this->baseUrl;

        try {
            return $this->send($endpoint, $data);
        } catch (ConnectException $e) {
            $failover = $this->failoverEndpoint($endpoint);

            if ($failover === null || ! $this->isSafeToRetry($e)) {
                throw new AzulException(
                    'Connection failed (possible IP whitelist or TLS issue): ' . $e->getMessage(),
                    0,
                    $e
                );
            }

            Log::channel('daily')->warning('Azul primary endpoint unreachable, retrying on failover', [
                'primary' => $endpoint,
                'failover' => $failover,
                'order_id' => $data['CustomOrderId'] ?? null,
                'error' => $e->getMessage(),
            ]);

            try {
                return $this->send($failover, $data);
            } catch (ConnectException $failoverError) {
                throw new AzulException(
                    'Connection failed on both the primary and failover endpoints: ' . $failoverError->getMessage(),
                    0,
                    $failoverError
                );
            }
        }
    }

    /**
     * Azul only runs a secondary host for production; the sandbox has no equivalent,
     * so failover stays disabled everywhere except against the production base URL.
     */
    public function failoverEndpoint(string $endpoint): ?string
    {
        if ($this->baseUrl !== ConfigurationEnum::PROD_URL->value) {
            return null;
        }

        $failoverBase = $this->app->get(ConfigurationEnum::AZUL_FAILOVER_URL->value)
            ?? $this->config['failover_url']
            ?? ConfigurationEnum::PROD_FAILOVER_URL->value;

        if (empty($failoverBase) || $failoverBase === $this->baseUrl) {
            return null;
        }

        // Callers pass endpoints built from baseUrl plus a query (?ProcessPost, ?VerifyPayment…).
        return str_replace($this->baseUrl, $failoverBase, $endpoint);
    }

    /**
     * Only retry when the request provably never reached Azul. A timeout (errno 28) is
     * excluded on purpose: the transaction may already have been authorized, and replaying
     * it on the secondary host would double-charge the cardholder. Those are reconciled
     * with VerifyPayment instead.
     */
    private function isSafeToRetry(ConnectException $e): bool
    {
        $errno = $e->getHandlerContext()['errno'] ?? null;

        return in_array($errno, [
            CURLE_COULDNT_RESOLVE_HOST,
            CURLE_COULDNT_CONNECT,
            CURLE_SSL_CONNECT_ERROR,
        ], true);
    }

    private function send(string $endpoint, array $data): array
    {
        $start = hrtime(true);
        $context = $this->buildLogContext($data, $endpoint);

        try {
            $response = $this->client->post($endpoint, ['json' => $data]);
            $body = $response->getBody()->getContents();
            $decoded = json_decode($body, true);

            if ($this->debugLog) {
                Log::channel('daily')->info('Azul API call', [
                    ...$context,
                    'response' => $decoded,
                    'response_time_ms' => (int) round((hrtime(true) - $start) / 1e6),
                    'http_status' => $response->getStatusCode(),
                ]);
            }

            return $decoded;
        } catch (ConnectException $e) {
            if ($this->debugLog) {
                Log::channel('daily')->error('Azul API connection failed', [
                    ...$context,
                    'error' => $e->getMessage(),
                    'response_time_ms' => (int) round((hrtime(true) - $start) / 1e6),
                ]);
            }

            // Rethrown as-is so post() can decide whether the failover host applies.
            throw $e;
        } catch (RequestException $e) {
            $res = $e->getResponse();
            $body = $res ? (string) $res->getBody() : null;
            $decoded = $body ? (json_decode($body, true) ?? []) : [];

            if ($this->debugLog) {
                Log::channel('daily')->error('Azul API request failed', [
                    ...$context,
                    'response' => $decoded,
                    'http_status' => $res?->getStatusCode(),
                    'error' => $e->getMessage(),
                    'response_time_ms' => (int) round((hrtime(true) - $start) / 1e6),
                ]);
            }

            throw new AzulException('Request failed: ' . $e->getMessage(), $res?->getStatusCode() ?? 0, $e, $decoded);
        }
    }

    private function buildLogContext(array $data, string $endpoint): array
    {
        $cardEnding = isset($data['CardNumber']) ? substr($data['CardNumber'], -4) : ($data['DataVaultToken'] ?? null);
        $masked = $data;

        if (isset($masked['CardNumber'])) {
            $masked['CardNumber'] = '****' . substr($masked['CardNumber'], -4);
        }
        if (isset($masked['CVC'])) {
            $masked['CVC'] = '***';
        }
        if (isset($masked['Expiration'])) {
            $masked['Expiration'] = '****' . substr($masked['Expiration'], -2);
        }

        return [
            'timestamp' => now()->toIso8601String(),
            'endpoint' => $endpoint,
            'order_id' => $data['CustomOrderId'] ?? null,
            'card_ending' => $cardEnding,
            'azul_order_id' => $data['AzulOrderId'] ?? null,
            'store' => $data['Store'] ?? null,
            'trx_type' => $data['TrxType'] ?? null,
            'request' => $masked,
        ];
    }

    public function getDataVaultUrl(): string
    {
        return $this->baseUrl . '?ProcessDatavault';
    }

    public function getPostUrl(): string
    {
        return $this->baseUrl . '?ProcessPost';
    }

    public function getVoidUrl(): string
    {
        return $this->baseUrl . '?ProcessVoid';
    }

    public function getVerifyUrl(): string
    {
        return $this->baseUrl . '?VerifyPayment';
    }

    public function getThreeDSMethodUrl(): string
    {
        return $this->baseUrl . '?processthreedsmethod';
    }

    public function getThreeDSChallengeUrl(): string
    {
        return $this->baseUrl . '?processthreedschallenge';
    }

    public function getGuzzleClient(): GuzzleClient
    {
        return $this->client;
    }
}
