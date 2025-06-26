<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Plusval;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use Kanvas\Connectors\Plusval\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected string $baseUrl;
    protected string $apiKey;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUrl = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->apiKey = $this->app->get(ConfigurationEnum::API_KEY->value);

        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new ValidationException('Plusval configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Content-Type' => 'application/json',
                'x-api-key' => $this->apiKey,
            ],
            'verify' => false,
        ]);
    }

    /**
     * Make a GET request to the API
     *
     * @throws GuzzleException
     */
    public function get(string $endpoint, array $params = []): array
    {
        // Make sure $endpoint doesn't start with a slash
        $endpoint = ltrim($endpoint, '/');

        $options = [
            'headers' => [
                'x-api-key' => $this->apiKey,
            ],
        ];

        if (! empty($params)) {
            $options['query'] = $params;
        }

        $response = $this->client->get($endpoint, $options);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Make a POST request to the API
     *
     * @throws GuzzleException
     */
    public function post(string $endpoint, array $data): array
    {
        // Make sure $endpoint doesn't start with a slash
        $endpoint = ltrim($endpoint, '/');

        $response = $this->client->post($endpoint, [
            'headers' => [
                'x-api-key' => $this->apiKey,
            ],
            'json' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    /**
     * Get deals by phone number and name
     *
     * @throws GuzzleException
     */
    public function getDeals(string $phone, string $name): array
    {
        $params = [
            'phone' => $phone,
            'name' => $name,
        ];

        return $this->get('api/v2/ai/deals', $params);
    }

    public function getProperties(string $phone, string $criteria): array
    {
        $params = [
            'phone' => $phone,
            'criteria' => $criteria,
        ];

        return $this->get('api/v2/ai/properties', $params);
    }

    /**
     * Get the current API key
     */
    public function getApiKey(): string
    {
        return $this->apiKey;
    }
}
