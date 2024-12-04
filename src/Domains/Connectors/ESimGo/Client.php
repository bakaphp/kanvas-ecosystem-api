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

    protected function request($method, $uri, $body): array
    {
        $response = $this->client->request($method, $uri, ! empty($body) ? ['json' => $body] : []);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function get($uri, $body = []): array
    {
        return $this->request('GET', $uri, $body);
    }

    public function post($uri, $body = []): array
    {
        return $this->request('POST', $uri, $body);
    }
}
