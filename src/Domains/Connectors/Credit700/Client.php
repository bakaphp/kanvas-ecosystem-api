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

    public function post(string $path, array $data = []): array
    {
        $response = $this->httpClient->post($this->apiBaseUrl . $path, [
            'headers' => [
                //'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => $data, // Use form_params for x-www-form-urlencoded
        ]);

        $responseBody = $response->getBody()->getContents();
        $xml = new SimpleXMLElement($this->sanitizeXml($responseBody));

        return json_decode(json_encode($xml), true); // Return as an associative array
    }

    protected function sanitizeXml(string $xml): string
    {
        // Fix the nested <Creditsystem_Error> tag issue
        $xml = preg_replace('/<Creditsystem_Error id=<Creditsystem_Error id="(\d+)">/', '<Creditsystem_Error id="$1">', $xml);

        // Ensure all tags are properly closed
        $xml = str_replace('</Creditsystem_Error></Creditsystem_Error>', '</Creditsystem_Error>', $xml);

        return $xml;
    }

    public function generateToken(): string
    {
        $response = $this->httpClient->post($this->apiBaseUrl . '/.auth/token', [
            'json' => [
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

    public function signUrl(string $unsignedUrl, string $signedBy, int $duration = 30): string
    {
        $this->generateToken();

        $response = $this->httpClient->post($this->apiBaseUrl . '/.auth/sign', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->accessToken,
                'Content-Type' => 'application/json',
            ],
           'json' => [
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
