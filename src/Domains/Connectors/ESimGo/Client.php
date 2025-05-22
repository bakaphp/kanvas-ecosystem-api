<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESimGo;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\ESimGo\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    public GuzzleClient $client;
    public string $baseUri = 'https://api.esim-go.com';
    public string $appToken;
    public int $perPage = 4000;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->appToken = $this->app->get(ConfigurationEnum::ESIM_GO_APP_KEY->value);

        if (empty($this->appToken)) {
            throw new ValidationException('ESimGo configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Content-Type' => 'application/json',
                'X-API-Key' => $this->appToken,
            ],
        ]);
    }

    protected function request(string $method, string $uri, array $body = []): array
    {
        $options = ! empty($body) ? ['json' => $body] : [];
        $response = $this->client->request($method, $uri, $options);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function get(string $uri, array $body = []): array
    {
        return $this->request('GET', $uri, $body);
    }

    public function post(string $uri, array $body = []): array
    {
        return $this->request('POST', $uri, $body);
    }

    public function put(string $uri, array $body = []): array
    {
        return $this->request('PUT', $uri, $body);
    }
}
