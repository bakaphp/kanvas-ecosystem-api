<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected string $baseUrl;
    protected string $apiKey;
    protected GuzzleClient $client;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected bool $outboundMode = false,
        ?string $apiKey = null,
    ) {
        $baseUrlEnum = $this->outboundMode
            ? ConfigurationEnum::BASE_URL_OUTBOUND
            : ConfigurationEnum::BASE_URL;

        $this->baseUrl = $app->get($baseUrlEnum->value);
        // Session management uses the account PAT (config); per-session ops (send-message) must pass
        // that session's own api_key. When given, it overrides the account key for this client.
        $this->apiKey = $apiKey !== null && $apiKey !== ''
            ? $apiKey
            : ($company->get(ConfigurationEnum::API_KEY->value) ?? $app->get(ConfigurationEnum::API_KEY->value));

        if (empty($this->baseUrl) || empty($this->apiKey)) {
            throw new ValidationException('Wasender configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function get(string $endpoint, array $queryParams = []): array
    {
        return $this->send('GET', $endpoint, ['query' => $queryParams]);
    }

    public function post(string $endpoint, array $data): array
    {
        return $this->send('POST', $endpoint, ['json' => $data]);
    }

    public function put(string $endpoint, array $data): array
    {
        return $this->send('PUT', $endpoint, ['json' => $data]);
    }

    public function delete(string $endpoint, array $queryParams = []): array
    {
        return $this->send('DELETE', $endpoint, ['query' => $queryParams]);
    }

    public function sendMessage(string $to, string $text): array
    {
        return $this->post('/api/send-message', [
            'to' => $to,
            'text' => $text,
        ]);
    }

    public static function validateCredentials(
        string $baseUrl,
        string $apiKey
    ): bool {
        try {
            $client = new GuzzleClient([
                'base_uri' => $baseUrl,
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                ],
            ]);

            $response = $client->get('/api/status');
            $data = json_decode($response->getBody()->getContents(), true);

            if (! is_array($data) || ! isset($data['status'])) {
                throw new ValidationException('Invalid response from Wasender API');
            }

            return $data['status'] === 'connected';
        } catch (GuzzleException $e) {
            throw new ValidationException(
                'Failed to connect to Wasender API: ' . $e->getMessage(),
                $e->getCode()
            );
        }
    }

    private function send(string $method, string $endpoint, array $options): array
    {
        try {
            $response = $this->client->request($method, $endpoint, $options);

            return json_decode($response->getBody()->getContents(), true);
        } catch (ClientException $e) {
            throw $this->rejection($e);
        }
    }

    /**
     * Guzzle raises ClientException for 4xx only, so anything reaching here is the API declining the
     * request rather than failing at it — hence its own type, which a caller can skip on. `error` is
     * WaSender's own key; without it the reason stays buried in Guzzle's "Client error: POST …" blurb.
     */
    private function rejection(ClientException $e): WaSenderRefusedException
    {
        $error = json_decode($e->getResponse()->getBody()->getContents(), true);
        $error = is_array($error) ? $error : [];

        return new WaSenderRefusedException(
            $error['message'] ?? $error['error'] ?? $e->getMessage(),
            $error['code'] ?? $e->getResponse()->getStatusCode()
        );
    }
}
