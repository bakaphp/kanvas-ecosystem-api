<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\RequestException;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use SimpleXMLElement;

class Client
{
    protected GuzzleClient $httpClient;
    protected string $account;
    protected string $password;
    protected string $clientId;
    protected string $clientSecret;
    protected string $baseUrl;

    public function __construct(
        protected AppInterface $app,
        bool $useProduction = true
    ) {
        $this->account = $this->app->get(ConfigurationEnum::ACCOUNT->value);
        $this->password = $this->app->get(ConfigurationEnum::PASSWORD->value);
        $this->clientId = $this->app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->clientSecret = $this->app->get(ConfigurationEnum::CLIENT_SECRET->value);

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new ValidationException('700Credit client credentials are not set for ' . $this->app->name);
        }

        $this->baseUrl = $useProduction
            ? 'https://gateway.700dealer.com'
            : 'https://gateway.700creditsolution.com';

        $this->httpClient = new GuzzleClient([
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
    }

    public function getToken(): string
    {
        try {
            $response = $this->httpClient->post($this->baseUrl . '/.auth/token', [
                'form_params' => [
                    'clientId' => $this->clientId,
                    'clientSecret' => $this->clientSecret,
                ],
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            return $responseBody['access_token'];
        } catch (RequestException $e) {
            throw new ValidationException('Failed to retrieve token: ' . $e->getMessage());
        }
    }

    public function signUrl(string $unsignedUrl, int $duration): string
    {
        try {
            $token = $this->getToken();
            $response = $this->httpClient->post($this->baseUrl . '/.auth/sign', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $token,
                ],
                'form_params' => [
                    'url' => $unsignedUrl,
                    'duration' => $duration,
                    'signedBy' => 'SystemIntegration',
                ],
            ]);

            $responseBody = json_decode($response->getBody()->getContents(), true);

            return $responseBody['url'];
        } catch (RequestException $e) {
            throw new ValidationException('Failed to sign URL: ' . $e->getMessage());
        }
    }

    public function post(array $data = []): SimpleXMLElement
    {
        $requestData = array_merge($data, [
            'PRODUCT' => 'CREDIT',
            'BUREAU' => 'XPN', // Choose from XPN, TU, or EFX
            'PASS' => '2',
            'PROCESS' => 'PCCREDIT',
        ]);

        try {
            $response = $this->httpClient->post($this->baseUrl . '/Request', [ // Add /Request here
                'form_params' => $requestData,
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->getToken(),
                ],
            ]);

            $responseBody = $response->getBody()->getContents();

            return new SimpleXMLElement($responseBody);
        } catch (RequestException $e) {
            throw new ValidationException('Failed to make API request: ' . $e->getMessage());
        }
    }
}
