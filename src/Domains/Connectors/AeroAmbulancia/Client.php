<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Kanvas\Connectors\AeroAmbulancia\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected ?string $token = null;
    protected string $baseUrl;
    protected string $email;
    protected string|int $password;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->email = $this->app->get(ConfigurationEnum::EMAIL->value);
        $this->password = $this->app->get(ConfigurationEnum::PASSWORD->value);

        if (empty($this->baseUrl) || empty($this->email) || empty($this->password)) {
            throw new ValidationException('AeroAmbulancia configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
            'verify' => false, // Try disabling SSL verification temporarily
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
    public function authenticate(): array
    {
        $client = new GuzzleClient(); // Create a fresh client instance

        $headers = [
            'Content-Type' => 'application/json',
        ];

        $body = '{
        "email": "' . $this->email . '",
        "password": "' . $this->password . '"
        }';

        $request = new Request('POST', $this->baseUrl . 'auth/login', $headers, $body);

        $response = $client->sendAsync($request)->wait();
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

        // Make sure $endpoint doesn't start with a slash
        $endpoint = ltrim($endpoint, '/');

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
