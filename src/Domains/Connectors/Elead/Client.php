<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use RuntimeException;

class Client
{
    protected PendingRequest $client;

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

        // Initialize the HTTP client
        $this->client = Http::baseUrl($this->baseUrl)
            ->withOptions([
                'curl' => [
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
            $response = Http::withHeaders([
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Authorization' => 'Basic ' . $this->authAuthorizationBasic,
            ])->asForm()->post($this->authBaseUrl . '/oauth2/aus1p1ixy7YL8cMq02p7/v1/token', [
                'grant_type' => $this->grantType,
                'scope' => $this->scope,
            ]);

            $token = $response->body();

            // Set the token in Redis with expiration
            Redis::setex($this->redisKey, 3300, $token);
        }

        return json_decode($token, true);
    }

    /**
     * Set this request headers.
     */
    protected function setHeaders(PendingRequest $request): PendingRequest
    {
        return $request->withHeaders([
            'subscription-id' => $this->subscriptionId,
            'Authorization' => 'Bearer ' . $this->auth()['access_token'],
            'Content-Type' => 'application/json',
        ]);
    }

    /**
     * Run Get request against Elead API.
     */
    public function get(string $path, array $params = []): array
    {
        $request = $this->setHeaders($this->client);

        if (! empty($params)) {
            $request = $request->withQueryParameters($params);
        }

        $response = $request->get($path);

        return $response->json() ?? [];
    }

    /**
     * Post to the api.
     */
    public function post(string $path, array $data, array $headers = []): array
    {
        $request = $this->setHeaders($this->client);

        if (! empty($headers)) {
            $request = $request->withHeaders($headers);
        }
        $response = $request->post($path, $data);

        return $response->json() ?? [];
    }

    /**
     * Put to the api.
     */
    public function put(string $path, array $data, array $headers = []): array
    {
        $request = $this->setHeaders($this->client);

        if (! empty($headers)) {
            $request = $request->withHeaders($headers);
        }

        $response = $request->put($path, $data);

        return $response->json() ?? [];
    }

    /**
     * Delete request to the api.
     */
    public function delete(string $path): array
    {
        $request = $this->setHeaders($this->client);
        $response = $request->delete($path);

        return $response->json() ?? [];
    }
}
