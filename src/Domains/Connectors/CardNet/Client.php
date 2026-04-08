<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\CardNet\Enums\ConfigurationEnum;
use Kanvas\Connectors\CardNet\Exceptions\CardNetException;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $privateKey;
    protected GuzzleClient $client;

    public function __construct(protected AppInterface $app)
    {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value)
            ?? ConfigurationEnum::SANDBOX_URL->value;

        $this->privateKey = $this->app->get(ConfigurationEnum::PRIVATE_KEY->value) ?? '';

        if (empty($this->privateKey)) {
            throw new ValidationException('CardNet configuration is missing: PRIVATE_KEY is required');
        }

        $this->client = new GuzzleClient([
            'base_uri' => rtrim($this->baseUrl, '/') . '/',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'Authorization' => 'Basic ' . $this->privateKey,
            ],
            'timeout' => 90,
            'connect_timeout' => 15,
        ]);
    }

    public function get(string $endpoint, array $params = []): array
    {
        try {
            $options = [];

            if (! empty($params)) {
                $options['query'] = $params;
            }

            $response = $this->client->get($endpoint, $options);
            $body = $response->getBody()->getContents();

            return json_decode($body, true) ?? [];
        } catch (ConnectException $e) {
            throw new CardNetException(
                'Connection failed (check TLS 1.2 and network access): ' . $e->getMessage(),
                0,
                $e,
            );
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $errorBody = [];
            $statusCode = 0;

            if ($response !== null) {
                $statusCode = $response->getStatusCode();
                $errorBody = json_decode((string) $response->getBody(), true) ?? [];
            }

            throw new CardNetException(
                $errorBody['Description'] ?? $errorBody['description'] ?? $errorBody['message'] ?? $e->getMessage(),
                $statusCode,
                $e,
                $errorBody,
            );
        }
    }

    public function post(string $endpoint, array $data = []): array
    {
        try {
            $response = $this->client->post($endpoint, ['json' => $data]);
            $body = $response->getBody()->getContents();

            return json_decode($body, true) ?? [];
        } catch (ConnectException $e) {
            throw new CardNetException(
                'Connection failed (check TLS 1.2 and network access): ' . $e->getMessage(),
                0,
                $e,
            );
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $errorBody = [];
            $statusCode = 0;

            if ($response !== null) {
                $statusCode = $response->getStatusCode();
                $errorBody = json_decode((string) $response->getBody(), true) ?? [];
            }

            throw new CardNetException(
                $errorBody['Description'] ?? $errorBody['description'] ?? $errorBody['message'] ?? $e->getMessage(),
                $statusCode,
                $e,
                $errorBody,
            );
        }
    }

    public function getPublicKey(): string
    {
        return $this->app->get(ConfigurationEnum::PUBLIC_KEY->value) ?? '';
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function getPrivateKey(): string
    {
        return $this->privateKey;
    }

    public function postWithQueryKeyAuth(string $endpoint, array $data = []): array
    {
        try {
            $url = rtrim($this->baseUrl, '/') . '/' . ltrim($endpoint, '/');
            $guzzle = new GuzzleClient([
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'timeout' => 90,
                'connect_timeout' => 15,
            ]);

            $response = $guzzle->post($url, [
                'query' => ['commerceKey' => $this->privateKey],
                'json' => $data,
            ]);

            $body = $response->getBody()->getContents();

            return json_decode($body, true) ?? [];
        } catch (ConnectException $e) {
            throw new CardNetException(
                'Connection failed (check TLS 1.2 and network access): ' . $e->getMessage(),
                0,
                $e,
            );
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $errorBody = [];
            $statusCode = 0;

            if ($response !== null) {
                $statusCode = $response->getStatusCode();
                $errorBody = json_decode((string) $response->getBody(), true) ?? [];
            }

            throw new CardNetException(
                $errorBody['Description'] ?? $errorBody['description'] ?? $errorBody['message'] ?? $e->getMessage(),
                $statusCode,
                $e,
                $errorBody,
            );
        }
    }
}
