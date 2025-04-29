<?php

namespace Kanvas\Domains\Connectors\AeroAmbulancia;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\ConfigurationEnum;

class Client
{
    protected GuzzleClient $client;
    protected ?string $token = null;
    protected string $baseUrl;
    protected string $email;
    protected string $password;

    public function __construct()
    {
        $this->baseUrl = config(ConfigurationEnum::BASE_URL->value);
        $this->email = config(ConfigurationEnum::EMAIL->value);
        $this->password = config(ConfigurationEnum::PASSWORD->value);

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Ensure we have a valid authentication token
     *
     * @throws GuzzleException
     */
    protected function ensureAuthenticated(): void
    {
        if (! $this->token) {
            $this->authenticate();
        }
    }

    /**
     * Authenticate with the AeroAmbulancia API
     *
     * @throws GuzzleException
     */
    protected function authenticate(): array
    {
        $response = $this->client->post('/auth/login', [
            'json' => [
                'email' => $this->email,
                'password' => $this->password,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);
        $this->token = $data['token'] ?? null;

        return $data;
    }

    /**
     * Make a POST request to the API
     *
     * @throws GuzzleException
     */
    public function post(string $endpoint, array $data): array
    {
        $this->ensureAuthenticated();

        $response = $this->client->post($endpoint, [
            'headers' => [
                'Authorization' => "Bearer {$this->token}",
            ],
            'json' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get the current authentication token
     */
    public function getToken(): ?string
    {
        return $this->token;
    }
}
