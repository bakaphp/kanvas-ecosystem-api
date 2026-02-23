<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Azul\Enums\ConfigurationEnum;
use Kanvas\Connectors\Azul\Exceptions\AzulException;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $auth1;
    protected string $auth2;
    protected GuzzleClient $client;

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

        if (empty($this->auth1) || empty($this->auth2)) {
            throw new ValidationException('Azul configuration is missing: Auth1 and Auth2 are required');
        }

        $certPath = $this->resolvePath(
            $this->app->get(ConfigurationEnum::AZUL_CERT_PATH->value) ?? $config['cert_path'] ?? null
        );
        $keyPath = $this->resolvePath(
            $this->app->get(ConfigurationEnum::AZUL_KEY_PATH->value) ?? $config['key_path'] ?? null
        );

        if (empty($certPath) || empty($keyPath)) {
            throw new ValidationException('Azul configuration is missing: cert_path and key_path are required');
        }

        if (! file_exists($certPath)) {
            throw new ValidationException("Azul cert file not found at: $certPath");
        }
        if (! file_exists($keyPath)) {
            throw new ValidationException("Azul key file not found at: $keyPath");
        }

        $guzzleConfig = [
            'headers' => [
                'Content-Type' => 'application/json',
                'Auth1'        => $this->auth1,
                'Auth2'        => $this->auth2,
            ],
            'timeout'         => 120,
            'connect_timeout' => 15,
            'cert'            => $certPath,
            'ssl_key'         => $keyPath,
        ];

        $caPath = $this->app->get(ConfigurationEnum::AZUL_CA_PATH->value)
            ?? $config['ca_path']
            ?? null;
        $caPath = $this->resolvePath($caPath);
        $verify = $this->app->get(ConfigurationEnum::AZUL_VERIFY_SSL->value)
            ?? $config['verify_ssl']
            ?? true;

        if ($verify === false) {
            $guzzleConfig['verify'] = false; // only for debugging
        } elseif (! empty($caPath)) {
            if (! file_exists($caPath)) {
                throw new ValidationException("Azul CA bundle not found at: $caPath");
            }
            $guzzleConfig['verify'] = $caPath;
        } else {
            $guzzleConfig['verify'] = true; // use system CA store
        }

        $this->client = new GuzzleClient($guzzleConfig);
    }

    public function post(array $data, ?string $url = null): array
    {
        try {
            $response = $this->client->post($url ?? $this->baseUrl, ['json' => $data]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ConnectException $e) {
            throw new AzulException('Connection failed (possible IP whitelist or TLS issue): ' . $e->getMessage(), 0, $e);
        } catch (RequestException $e) {
            $res = $e->getResponse();
            $body = $res ? (string) $res->getBody() : null;
            throw new AzulException('Request failed: ' . $e->getMessage(), $res?->getStatusCode() ?? 0, $e, $body ? (json_decode($body, true) ?? []) : []);
        }
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

    public function getGuzzleClient(): GuzzleClient
    {
        return $this->client;
    }

    /**
     * Resolve a path - if relative, make it absolute from base_path()
     */
    private function resolvePath(?string $path): ?string
    {
        if (empty($path)) {
            return null;
        }

        // If already absolute, return as-is
        if (str_starts_with($path, '/') || (strlen($path) > 1 && $path[1] === ':')) {
            return $path;
        }

        // Otherwise, make it relative to base_path
        return base_path($path);
    }
}
