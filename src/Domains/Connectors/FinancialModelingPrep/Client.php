<?php

declare(strict_types=1);

namespace Kanvas\Connectors\FinancialModelingPrep;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\FinancialModelingPrep\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl = 'https://financialmodelingprep.com';
    protected string $apiKey;
    protected GuzzleClient $httpClient;

    public function __construct(AppInterface $app)
    {
        $key = $app->get(ConfigurationEnum::FMP_API_KEY->value);

        if (empty($key)) {
            throw new ValidationException('Financial Modeling Prep API key is not set for ' . $app->name);
        }

        $this->apiKey = (string) $key;
        $this->httpClient = new GuzzleClient([
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public static function getInstance(AppInterface $app): self
    {
        return new self($app);
    }

    public function get(string $path, array $params = []): array
    {
        try {
            $response = $this->httpClient->get($this->baseUrl . $path, [
                'query' => array_merge($params, ['apikey' => $this->apiKey]),
            ]);

            return json_decode($response->getBody()->getContents(), true) ?? [];
        } catch (RequestException $e) {
            throw new ValidationException('FMP API request failed: ' . $e->getMessage());
        }
    }

    public static function validateCredentials(string $key): bool
    {
        try {
            $client = new GuzzleClient(['timeout' => 10]);
            $response = $client->get('https://financialmodelingprep.com/stable/profile', [
                'query' => ['symbol' => 'AAPL', 'apikey' => $key],
            ]);

            $data = json_decode($response->getBody()->getContents(), true);

            return is_array($data) && ! empty($data);
        } catch (RequestException) {
            return false;
        }
    }
}
