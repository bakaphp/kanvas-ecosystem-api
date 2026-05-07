<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Ofac;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Ofac\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected AppInterface $app;
    protected string $baseUrl = 'https://search.ofac-api.com';

    /**
     * Constructor.
     */
    public function __construct(AppInterface $app)
    {
        $this->app = $app;
        $this->validateConfiguration();

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'curl.options' => [
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ]);
    }

    /**
     * Validate that required configuration is present.
     */
    protected function validateConfiguration(): void
    {
        $apiKey = $this->app->get(ConfigurationEnum::OFAC_API_KEY->value);

        if (empty($apiKey)) {
            throw new ValidationException('OFAC API key is not configured for app: ' . $this->app->getId());
        }
    }

    /**
     * Post to the api.
     */
    public function post(string $path, array $body, array $params = []): array
    {
        $body['apiKey'] = $this->app->get(ConfigurationEnum::OFAC_API_KEY->value);

        $response = $this->client->post(
            $path,
            [
                'body' => json_encode($body),
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
            ],
            $params
        );

        $returnData = $response->getBody()->getContents();

        /** @psalm-suppress MixedReturnStatement */
        return json_decode($returnData, true);
    }
}
