<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Illuminate\Support\Facades\App;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Connectors\EchoPay\Exceptions\EchoPayException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Payments\Contracts\HttpClientInterface;
use Override;

class Client implements HttpClientInterface
{
    protected string $baseUrl;
    protected string $appToken;
    protected string $clientId;
    protected string $secret;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value) ?? $this->defaultBaseUrl();

        $this->clientId = $this->app->get(ConfigurationEnum::CLIENT_ID->value) ?? $config['client_id'] ?? '';
        $this->secret = $this->app->get(ConfigurationEnum::SECRET->value) ?? $config['secret'] ?? '';

        if (empty($this->clientId) || empty($this->secret)) {
            throw new ValidationException('Echo Pay configuration is missing');
        }

        $this->appToken = $this->getAccessToken();

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->appToken,
            ],
            'timeout' => 90,
            'connect_timeout' => 15,
        ]);
    }

    /**
     * Outside production the fallback stays on the sandbox: the test suites run with sandbox
     * credentials and no base-url setting, and must never reach the live gateway.
     */
    protected function defaultBaseUrl(): string
    {
        return App::environment('production')
            ? ConfigurationEnum::PRODUCTION_URL->value
            : ConfigurationEnum::SANDBOX_URL->value;
    }

    public function getAccessToken(): string
    {
        $client = new GuzzleClient();
        $result = $client->post($this->baseUrl . ConfigurationEnum::AUTHORIZATION_PATH->value, [
            'json' => [
                'email' => $this->clientId,
                'password' => $this->secret,
            ],
        ]);

        $body = json_decode($result->getBody()->getContents());

        return $body->data->token;
    }

    private function toEchoPayException(RequestException $e): EchoPayException
    {
        $errorBody = [];
        $statusCode = 0;

        if ($e->hasResponse()) {
            $response = $e->getResponse();
            $errorBody = json_decode($response->getBody()->getContents(), true) ?? [];
            $statusCode = $response->getStatusCode();
        }

        return new EchoPayException(
            $errorBody['message'] ?? $e->getMessage(),
            $statusCode,
            $e,
            $errorBody
        );
    }

    #[Override]
    public function get(string $endpoint): array
    {
        try {
            $response = $this->client->get($endpoint);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (RequestException $e) {
            throw $this->toEchoPayException($e);
        }
    }

    #[Override]
    public function post(string $endpoint, array $data, ?int $timeout = null): array
    {
        try {
            $options = ['json' => $data];

            if ($timeout !== null) {
                $options['timeout'] = $timeout;
            }

            $response = $this->client->post($endpoint, $options);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (RequestException $e) {
            throw $this->toEchoPayException($e);
        }
    }

    #[Override]
    public function patch(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->patch($endpoint, [
                'json' => $data,
            ]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (RequestException $e) {
            throw $this->toEchoPayException($e);
        }
    }

    #[Override]
    public function delete(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->client->delete($endpoint);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (RequestException $e) {
            throw $this->toEchoPayException($e);
        }
    }
}
