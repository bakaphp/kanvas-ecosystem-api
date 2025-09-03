<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Illuminate\Support\Facades\Redis;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use RuntimeException;

class Client
{
    protected GuzzleClient $client;

    protected string $authBaseUrl = 'https://identity.fortellis.io';
    protected string $baseUrl = 'https://api.fortellis.io/cdk-test';
    protected string $grantType = 'client_credentials';
    protected string $scope = 'anonymous';
    protected string $clientKey;
    protected string $clientSecret;
    protected string $authAuthorizationBasic;
    protected string $subscriptionId;
    protected string $redisKey = 'eLeadAuthToken';

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
        $subscriptionId = $company->get(CustomFieldEnum::COMPANY->value);

        if (empty($subscriptionId)) {
            throw new RuntimeException('No ELeads configured for this company ' . $company->id);
        }

        $this->subscriptionId = $subscriptionId;
        $this->clientKey = $app->get(ConfigurationEnum::ELEAD_API_KEY->value);
        $this->clientSecret = $app->get(ConfigurationEnum::ELEAD_API_SECRET->value);

        $this->authAuthorizationBasic = base64_encode($this->clientKey . ':' . $this->clientSecret);

        // Initialize the Guzzle client
        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUrl,
            'curl.options' => [
                CURLOPT_SSLVERSION => CURL_SSLVERSION_TLSv1_2,
            ],
        ]);
    }

    /**
     * Authenticate with the Elead API.
     */
    public function auth(): array
    {
        if (! $token = Redis::get($this->redisKey)) {
            $response = $this->client->post(
                $this->authBaseUrl . '/oauth2/aus1p1ixy7YL8cMq02p7/v1/token',
                [
                    'headers' => [
                        'Content-Type' => 'application/x-www-form-urlencoded',
                        'Authorization' => 'Basic ' . $this->authAuthorizationBasic,
                    ],
                    'form_params' => [
                        'grant_type' => $this->grantType,
                        'scope' => $this->scope,
                    ],
                ]
            );

            $token = $response->getBody()->getContents();

            // Set the token in Redis with expiration
            Redis::setex($this->redisKey, 3300, $token);
        }

        return json_decode($token, true);
    }

    /**
     * Set this request headers.
     */
    protected function setHeaders(array $headers): array
    {
        $headers['headers']['subscription-id'] = $this->subscriptionId;
        $headers['headers']['Authorization'] = 'Bearer ' . $this->auth()['access_token'];

        return $headers;
    }

    /**
     * Run Get request against Elead API.
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
    public function post(string $path, array $data, array $params = []): array
    {
        $params = $this->setHeaders($params);
        if (! isset($params['headers']['Content-Type'])) {
            $params['headers']['Content-Type'] = 'application/json';
        }

        $params['body'] = json_encode($data);

        $response = $this->client->post(
            $path,
            $params
        );

        $returnData = $response->getBody()->getContents();

        return $returnData ? json_decode(
            $returnData,
            true
        ) : [];
    }

    /**
     * Put to the api.
     */
    public function put(string $path, array $data, array $params = []): array
    {
        $params = $this->setHeaders($params);
        if (! isset($params['headers']['Content-Type'])) {
            $params['headers']['Content-Type'] = 'application/json';
        }

        $params['body'] = json_encode($data);

        $response = $this->client->put(
            $path,
            $params
        );

        return ! empty($response->getBody()->getContents()) ? json_decode(
            $response->getBody()->getContents(),
            true
        ) : [];
    }

    /**
     * Delete request to the api.
     */
    public function delete(string $path): array
    {
        $params = $this->setHeaders([]);
        if (! isset($params['headers']['Content-Type'])) {
            $params['headers']['Content-Type'] = 'application/json';
        }

        $response = $this->client->delete(
            $path,
            $params
        );

        return ! empty($response->getBody()->getContents()) ? json_decode(
            $response->getBody()->getContents(),
            true
        ) : [];
    }
}
