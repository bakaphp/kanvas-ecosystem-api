<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $httpClient;
    protected string $apiKey;
    protected string $baseUrl = 'https://api.mailgun.net';

    public function __construct(
        protected AppInterface $app
    ) {
        $this->apiKey = (string) ($this->app->get(ConfigurationEnum::API_KEY->value));

        if (empty($this->apiKey)) {
            throw new ValidationException('Mailgun API key is not configured for app: ' . $this->app->name);
        }

        $this->httpClient = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'timeout' => 15,
            'auth' => ['api', $this->apiKey],
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    public function validateAddress(string $email): array
    {
        $response = $this->httpClient->get('/v4/address/validate', [
            'query' => ['address' => $email],
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }
}
