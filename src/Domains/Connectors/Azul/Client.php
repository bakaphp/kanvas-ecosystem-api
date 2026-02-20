<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Azul;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
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

        $this->auth1 = $this->app->get(ConfigurationEnum::AZUL_AUTH1->value) ?? $config['auth1'] ?? '';
        $this->auth2 = $this->app->get(ConfigurationEnum::AZUL_AUTH2->value) ?? $config['auth2'] ?? '';

        if (empty($this->auth1) || empty($this->auth2)) {
            throw new ValidationException('Azul configuration is missing: Auth1 and Auth2 are required');
        }

        $certPath = $this->app->get(ConfigurationEnum::AZUL_CERT_PATH->value) ?? $config['cert_path'] ?? null;
        $keyPath  = $this->app->get(ConfigurationEnum::AZUL_KEY_PATH->value)  ?? $config['key_path']  ?? null;

        if (empty($certPath) || empty($keyPath)) {
            throw new ValidationException('Azul configuration is missing: cert_path and key_path are required for mTLS');
        }

        $this->client = new GuzzleClient([
            'headers' => [
                'Content-Type' => 'application/json',
                'Auth1'        => $this->auth1,
                'Auth2'        => $this->auth2,
            ],
            'cert'            => $certPath,
            'ssl_key'         => $keyPath,
            'timeout'         => 120,
            'connect_timeout' => 15,
        ]);
    }

    public function post(array $data): array
    {
        try {
            $response = $this->client->post($this->baseUrl, ['json' => $data]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $errorBody = json_decode($response->getBody()->getContents(), true);
            $errorMessage = $errorBody['ErrorDescription'] ?? $errorBody['ResponseMessage'] ?? $e->getMessage();

            throw new AzulException(
                $errorMessage,
                $response->getStatusCode(),
                $e,
                $errorBody
            );
        }
    }
}
