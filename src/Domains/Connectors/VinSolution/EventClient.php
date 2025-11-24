<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution;

use GuzzleHttp\Client as GuzzleClient;
use Override;

class EventClient extends Client
{
    #[Override]
    protected function setHeaders(array $headers): array
    {
        $headers['headers']['x-api-key'] = ! $this->useDigitalShowRoomKey ? $this->apiKey : $this->apiKeyDigitalShowRoom;
        $headers['headers']['Authorization'] = 'Bearer ' . $this->auth()['access_token'];

        return $headers;
    }

    protected function overWriteClient(): void
    {
        $this->client = new GuzzleClient(
            [
               'base_uri' => $this->eventBaseUrl,
               'curl.options' => [
                   CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
               ],
            ]
        );
    }

    #[Override]
    public function get(string $path, array $params = []): array
    {
        $this->overWriteClient();

        $response = $this->client->get(
            $path,
            $this->setHeaders($params)
        );

        return json_decode(
            $response->getBody()->getContents(),
            true
        );
    }

    #[Override]
    public function post(string $path, string $json, array $params = []): array
    {
        $this->overWriteClient();

        $params = $this->setHeaders($params);
        if (! isset($params['headers']['Content-Type'])) {
            $params['headers']['Content-Type'] = 'application/json';
        }

        $params['body'] = $json;

        $response = $this->client->post(
            $path,
            $params
        );

        return json_decode(
            $response->getBody()->getContents(),
            true
        );
    }

    #[Override]
    public function put(string $path, string $json, array $params = []): array
    {
        $this->overWriteClient();

        $params = $this->setHeaders($params);
        if (! isset($params['headers']['Content-Type'])) {
            $params['headers']['Content-Type'] = 'application/json';
        }

        $params['body'] = $json;

        $response = $this->client->put(
            $path,
            $params
        );

        return ! empty($response->getBody()->getContents()) ? json_decode(
            $response->getBody()->getContents(),
            true
        ) : [];
    }
}
