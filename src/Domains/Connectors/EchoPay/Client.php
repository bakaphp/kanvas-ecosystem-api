<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Connectors\EchoPay\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
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
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value) ?? ConfigurationEnum::SANDBOX_URL->value;
        $this->clientId = $this->app->get(ConfigurationEnum::CLIENT_ID->value) ?? $config['client_id'] ?? '';
        $this->secret = $this->app->get(ConfigurationEnum::SECRET->value) ?? $config['secret'] ?? '';

        if (empty($this->clientId) || empty($this->secret)) {
            throw new ValidationException('Echo Pay configuration is missing');
        }

        $this->appToken = $this->getAccessToken();

        $this->client = new GuzzleClient([
            'base_uri' => ConfigurationEnum::SANDBOX_URL->value,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->appToken,
            ],
        ]);
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

    /**
     * Perform a GET request to the API.
     */
    public function get(string $endpoint): array
    {
        try {
            $response = $this->client->get($endpoint);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            throw $e;
        }
    }

    /**
     * Perform a POST request to the API.
     */
    public function post(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->post($endpoint, [
                'json' => $data,
            ]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            throw $e;
        }
    }
}
