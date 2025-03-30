<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    public int $dealerId;
    public int $userId;
    protected string $authBaseUrl = 'https://authentication.vinsolutions.com';
    protected string $baseUrl = 'https://api.vinsolutions.com';
    protected string $grantType = 'client_credentials';
    protected string $scope = 'PublicAPI';
    protected string $clientId;
    protected string $clientSecret;
    protected string $apiKey;
    protected string $apiKeyDigitalShowRoom;
    protected string $redisKey = 'vinSolutionAuthToken';
    protected bool $useDigitalShowRoomKey = false;

    /**
     * Constructor.
     */
    public function __construct(int $dealerId, int $userId, ?AppInterface $app = null)
    {
        $app = $app ?? app(Apps::class);
        $this->dealerId = $dealerId;
        $this->userId = $userId;

        if (app()->environment() !== 'production') {
            $this->baseUrl = 'https://sandbox.api.vinsolutions.com';
        }

        $this->clientId = $app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->clientSecret = $app->get(ConfigurationEnum::CLIENT_SECRET->value);
        $this->apiKey = $app->get(ConfigurationEnum::API_KEY->value);
        $this->apiKeyDigitalShowRoom = $app->get(ConfigurationEnum::API_KEY_DIGITAL_SHOWROOM->value);

        if (! $this->clientId || ! $this->clientSecret || ! $this->apiKey) {
            throw new ValidationException('VinSolutions API keys not set');
        }

        $this->redisKey .= '-v3-' . $app->getId();
        $this->client = new GuzzleClient(
            [
                'base_uri' => $this->baseUrl,
                'curl.options' => [
                    CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
                ],
            ]
        );
    }

    /**
     * Use digital showroom key.
     */
    public function useDigitalShowRoomKey(): void
    {
        $this->useDigitalShowRoomKey = true;
    }

    /**
     * Authenticate with the VinSolutions API.
     */
    public function auth(): array
    {
        if (($token = Redis::get($this->redisKey)) === null) {
            $response = $this->client->post(
                $this->authBaseUrl . '/connect/token',
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                    ],
                    'form_params' => [
                        'grant_type' => $this->grantType,
                        'client_id' => $this->clientId,
                        'client_secret' => $this->clientSecret,
                        'scope' => $this->scope,
                    ],
                ]
            );

            $token = $response->getBody()->getContents();

            //set the token in redis
            Redis::set(
                $this->redisKey,
                $token,
                'EX',
                1800
            );
        }

        return json_decode($token, true);
    }

    /**
     * Set this request headers.
     */
    protected function setHeaders(array $headers): array
    {
        $headers['headers']['api_key'] = ! $this->useDigitalShowRoomKey ? $this->apiKey : $this->apiKeyDigitalShowRoom;
        $headers['headers']['Authorization'] = 'Bearer ' . $this->auth()['access_token'];

        return $headers;
    }

    /**
     * Run Get request against VinSolutions API.
     */
    public function get(string $path, array $params = []): array
    {
        $response = $this->client->get(
            $path,
            $this->setHeaders($params)
        );

        return json_decode(
            $response->getBody()->getContents(),
            true
        );
    }

    /**
     * Post to the api.
     */
    public function post(string $path, string $json, array $params = []): array
    {
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

    /**
     * Post to the api.
     */
    public function put(string $path, string $json, array $params = []): array
    {
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
