<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $apiKey;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->apiKey = $this->app->get(ConfigurationEnum::API_KEY->value);

        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new ValidationException('Wasender configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
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
                $error['message'] ?? $e->getMessage(),
                (int)($error['code'] ?? $e->getCode())
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
                $error['message'] ?? $e->getMessage(),
                (int)($error['code'] ?? $e->getCode())
            );
        }
    }

    /**
     * Perform a PUT request to the API.
     */
    public function put(string $endpoint, array $data): array
    {
        try {
            $response = $this->client->put($endpoint, [
                'json' => $data,
            ]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $body = $response->getBody()->getContents();
            $error = json_decode($body, true);

            throw new ValidationException(
                $error['message'] ?? $e->getMessage(),
                (int)($error['code'] ?? $e->getCode())
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
                $error['message'] ?? $e->getMessage(),
                (int)($error['code'] ?? $e->getCode())
            );
        }
    }

    /**
     * Send a WhatsApp text message.
     */
    public function sendMessage(string $to, string $text): array
    {
        return $this->post('/api/send-message', [
            'to' => $to,
            'text' => $text,
        ]);
    }

    /**
     * Validate API credentials.
     */
    public static function validateCredentials(
        string $baseUrl,
        string $apiKey
    ): bool {
        try {
            $client = new GuzzleClient([
                'base_uri' => $baseUrl,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            // Try to make a simple request to check if the credentials are valid
            // You might need to adjust this endpoint based on WasenderAPI's documentation
            $response = $client->get('/api/status');

            $body = $response->getBody()->getContents();
            $data = json_decode($body, true);

            // Make sure we got a valid response
            if (! is_array($data) || ! isset($data['status'])) {
                throw new ValidationException('Invalid response from Wasender API');
            }

            return $data['status'] === 'connected';
        } catch (GuzzleException $e) {
            throw new ValidationException(
                'Failed to connect to Wasender API: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }
}
