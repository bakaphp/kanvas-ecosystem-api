<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Apollo;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Apollo\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $httpClient;
    protected string $apiKey;
    protected string $baseUrl = 'https://api.apollo.io/v1';

    public function __construct(
        protected AppInterface $app
    ) {
        $this->apiKey = $this->app->get(ConfigurationEnum::APOLLO_API_KEY->value);

        if (empty($this->apiKey)) {
            throw new ValidationException('Apollo API key is not configured for app: ' . $this->app->name);
        }

        $this->httpClient = new GuzzleClient([
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Cache-Control' => 'no-cache',
                'X-Api-Key' => $this->apiKey,
            ],
        ]);
    }

    public function get(string $path, array $query = []): array
    {
        $response = $this->httpClient->get("{$this->baseUrl}{$path}", [
            'query' => $query,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    public function post(string $path, array $data = [], array $query = []): array
    {
        $response = $this->httpClient->post("{$this->baseUrl}{$path}", [
            'json' => $data,
            'query' => $query,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }
}
