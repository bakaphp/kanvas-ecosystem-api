<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use RuntimeException;
use SimpleXMLElement;

class Client
{
    protected string $apiBaseUrl = 'https://gateway.700dealer.com'; // Production URL
    protected GuzzleClient $httpClient;
    protected string $account;
    protected string $password;
    protected string $clientId;
    protected string $clientSecret;
    protected ?string $accessToken = null;

    public function __construct(
        protected AppInterface $app
    ) {
        $this->clientId = $this->app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->clientSecret = $this->app->get(ConfigurationEnum::CLIENT_SECRET->value);

        if (! app()->environment('production')) {
            $this->apiBaseUrl = 'https://gateway.700creditsolution.com';
        }

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new ValidationException('700Credit credentials are not set for ' . $this->app->name);
        }

        $this->httpClient = new GuzzleClient([
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    }

    public function get(string $path, array $params = []): array
    {
        throw new RuntimeException('GET method is not applicable for 700Credit integration.');
    }

    public function post(string $path, array $data = []): SimpleXMLElement
    {
        $this->generateToken();

        $response = $this->httpClient->post($this->apiBaseUrl . $path, [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
            ],
            'json' => $data,
        ]);

        $responseBody = $response->getBody()->getContents();

        // Process XML response
        return new SimpleXMLElement($responseBody);
    }

    public function generateToken(): string
    {
        $response = $this->httpClient->post($this->apiBaseUrl . '/.auth/token', [
             [
                'ClientId' => $this->clientId,
                'ClientSecret' => $this->clientSecret,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (! isset($data['access_token'])) {
            throw new RuntimeException('Failed to generate access token.');
        }

        $this->accessToken = $data['access_token'];

        return $this->accessToken;
    }

    public function signUrl(string $unsignedUrl, int $duration, string $signedBy): string
    {
        if (! $this->accessToken) {
            throw new ValidationException('Access token is missing. Generate the token first.');
        }

        $response = $this->httpClient->post($this->apiBaseUrl . '/.auth/sign', [
            [
                'url' => $unsignedUrl,
                'duration' => $duration,
                'signedBy' => $signedBy,
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (! isset($data['url'])) {
            throw new RuntimeException('Failed to sign the URL.');
        }

        return $data['url'];
    }
}
