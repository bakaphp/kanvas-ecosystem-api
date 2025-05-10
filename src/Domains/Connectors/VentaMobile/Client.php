<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VentaMobile;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\VentaMobile\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $username;
    protected string $password;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->username = $this->app->get(ConfigurationEnum::USERNAME->value);
        $this->password = $this->app->get(ConfigurationEnum::PASSWORD->value);

        if (empty($this->baseUrl) || empty($this->username) || empty($this->password)) {
            throw new ValidationException('VentaMobile configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'auth' => [$this->username, $this->password],
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    /**
     * Perform a GET request to the API.
     */
    public function get(string $endpoint, array $queryParams = []): array
    {
        try {
            $response = $this->client->get($endpoint, [
                'query' => $queryParams,
            ]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $error = json_decode($body, true);

            throw new ValidationException(
                $error['MESSAGE'] ?? $e->getMessage(),
                $error['RESULT_CODE'] ?? $e->getCode()
            );
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
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $error = json_decode($body, true);

            throw new ValidationException(
                $error['MESSAGE'] ?? $e->getMessage(),
                $error['RESULT_CODE'] ?? $e->getCode()
            );
        }
    }

    /**
     * Perform a DELETE request to the API.
     */
    public function delete(string $endpoint, array $queryParams = []): array
    {
        try {
            $response = $this->client->delete($endpoint, [
                'query' => $queryParams,
            ]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $error = json_decode($body, true);

            throw new ValidationException(
                $error['MESSAGE'] ?? $e->getMessage(),
                $error['RESULT_CODE'] ?? $e->getCode()
            );
        }
    }

    public static function validateCredentials(
        string $baseUrl,
        string $username,
        string $password
    ): bool {
        try {
            $client = new GuzzleClient([
                'base_uri' => $baseUrl,
                'auth' => [$username, $password],
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->get('/get/dictionary', [
                'query' => [
                    'dict' => 'tariff_plan',
                ],
            ]);

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            // Make sure we got a valid response
            if (! is_array($data)) {
                throw new ValidationException('Invalid response from VentaMobile API');
            }

            return true;
        } catch (GuzzleException $e) {
            throw new ValidationException(
                'Failed to connect to VentaMobile API: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }
}
