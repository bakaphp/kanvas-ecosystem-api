<?php

declare(strict_types=1);

namespace Kanvas\Connectors\OfferLogix;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;

class Client
{
    protected GuzzleClient $client;
    protected string $baseUrl = 'https://www.offerlogix.net/quote';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->client = new GuzzleClient(
            [
                'base_uri' => $this->baseUrl,
                'curl.options' => [
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                ],
            ]
        );
    }

    public function post(string $path, array $body, array $params = []): array
    {
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
