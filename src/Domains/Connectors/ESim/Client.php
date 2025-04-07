<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ESim;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\ESim\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected string $baseUri;
    protected string $appToken;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUri = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->appToken = $this->app->get(ConfigurationEnum::APP_TOKEN->value);

        if (empty($this->baseUri) || empty($this->appToken)) {
            throw new ValidationException('ESim configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $this->appToken,
            ],
        ]);
    }

    protected function request($method, $uri, $body): array
    {
        $response = $this->client->request($method, $uri, [
            'json' => $body,
        ]);

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
