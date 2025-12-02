<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Tookan;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Connectors\Tookan\Enums\ConfigurationEnum;
use Kanvas\Connectors\Tookan\Exceptions\TookanException;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $apiKey;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected array $config = []
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value) ?? ConfigurationEnum::SANDBOX_URL->value;
        $this->apiKey = $this->app->get(ConfigurationEnum::API_KEY->value) ?? $config['api_key'] ?? '';

        if (empty($this->apiKey)) {
            throw new ValidationException('Tookan API Key is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    /**
     * Perform a GET request to the API.
     */
    public function get(string $endpoint, array $params = []): array
    {
        try {
            $response = $this->client->get($endpoint, [
                'query' => array_merge(['api_key' => $this->apiKey], $params),
            ]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true);
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $errorBody = json_decode($response->getBody()->getContents(), true);

            $errorMessage = $errorBody['message'] ?? $e->getMessage();

            throw new TookanException(
                $errorMessage,
                (int) $response->getStatusCode(),
                $e,
                $errorBody
            );
        }
    }

    /**
     * Perform a POST request to the API.
     */
    public function post(string $endpoint, array $data): array
    {
        try {
            // Tookan requires api_key in the request body
            $data['api_key'] = $this->apiKey;

            $response = $this->client->post($endpoint, [
                'json' => $data,
            ]);
            $body = $response->getBody()->getContents();

            $result = json_decode($body, true);

            // Tookan API returns status code in response body
            if (isset($result['status']) && $result['status'] != 200) {
                throw new TookanException(
                    $result['message'] ?? 'Tookan API error',
                    $result['status'],
                    null,
                    $result
                );
            }

            return $result;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $errorBody = json_decode($response->getBody()->getContents(), true);

            $errorMessage = $errorBody['message'] ?? $e->getMessage();

            throw new TookanException(
                $errorMessage,
                (int) $response->getStatusCode(),
                $e,
                $errorBody
            );
        }
    }

    /**
     * Perform a PUT request to the API.
     */
    public function put(string $endpoint, array $data): array
    {
        try {
            $data['api_key'] = $this->apiKey;

            $response = $this->client->put($endpoint, [
                'json' => $data,
            ]);
            $body = $response->getBody()->getContents();

            $result = json_decode($body, true);

            if (isset($result['status']) && $result['status'] != 200) {
                throw new TookanException(
                    $result['message'] ?? 'Tookan API error',
                    $result['status'],
                    null,
                    $result
                );
            }

            return $result;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $errorBody = json_decode($response->getBody()->getContents(), true);

            $errorMessage = $errorBody['message'] ?? $e->getMessage();

            throw new TookanException(
                $errorMessage,
                (int) $response->getStatusCode(),
                $e,
                $errorBody
            );
        }
    }

    /**
     * Perform a DELETE request to the API.
     */
    public function delete(string $endpoint, array $data = []): array
    {
        try {
            $data['api_key'] = $this->apiKey;

            $response = $this->client->delete($endpoint, [
                'json' => $data,
            ]);
            $body = $response->getBody()->getContents();

            $result = json_decode($body, true);

            if (isset($result['status']) && $result['status'] != 200) {
                throw new TookanException(
                    $result['message'] ?? 'Tookan API error',
                    $result['status'],
                    null,
                    $result
                );
            }

            return $result;
        } catch (ClientException $e) {
            $response = $e->getResponse();
            $errorBody = json_decode($response->getBody()->getContents(), true);

            $errorMessage = $errorBody['message'] ?? $e->getMessage();

            throw new TookanException(
                $errorMessage,
                (int) $response->getStatusCode(),
                $e,
                $errorBody
            );
        }
    }
}
