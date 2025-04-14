<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Airalo;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use Kanvas\Connectors\Airalo\Enums\ConfigurationEnum;

class Client
{
    protected string $baseUrl;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json'
            ],
        ]);
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
