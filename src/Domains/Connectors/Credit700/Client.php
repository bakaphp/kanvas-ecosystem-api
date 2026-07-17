<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Credit700;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Credit700\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use RuntimeException;
use SimpleXMLElement;

class Client
{
    protected string $apiBaseUrl = 'https://gateway.700dealer.com'; // Production URL
    protected string $xmlGatewayUrl = 'https://www.700Dealer.com/XCRS/Service.aspx'; // Production XML Gateway (SAVEONLY/RouteOne)
    protected GuzzleClient $httpClient;
    protected string $account;
    protected string $password;
    protected string $clientId;
    protected string $clientSecret;
    protected ?string $accessToken = null;

    public function __construct(
        protected AppInterface $app,
        protected ?CompanyInterface $company = null
    ) {
        $this->clientId = $app->get(ConfigurationEnum::CLIENT_ID->value);
        $this->clientSecret = $app->get(ConfigurationEnum::CLIENT_SECRET->value);

        if (app()->environment() !== 'production') {
            $this->apiBaseUrl = 'https://gateway.700creditsolution.com';
            $this->xmlGatewayUrl = 'https://www.700CreditSolution.com/XCRS/Service.aspx';
        }

        if (empty($this->clientId) || empty($this->clientSecret)) {
            throw new ValidationException('700Credit credentials are not set for ' . $this->app->name);
        }

        $this->httpClient = new GuzzleClient([
            'timeout' => 20,
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

        return $this->decodeXmlResponse($response->getBody()->getContents());
    }

    /**
     * Posts a SAVEONLY/RouteOne payload to the 700Credit XML Gateway.
     *
     * The XML Gateway lives on a different host/path than the OAuth gateway
     * (www.700Dealer.com/XCRS/Service.aspx vs gateway.700dealer.com), and
     * authenticates via ACCOUNT/PASSWD in the body — not a bearer token.
     */
    public function postToXmlGateway(array $data = []): array
    {
        $response = $this->httpClient->post($this->xmlGatewayUrl, [
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'form_params' => $data,
        ]);

        return $this->decodeXmlResponse($response->getBody()->getContents());
    }

    protected function decodeXmlResponse(string $responseBody): array
    {
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
                'signedBy' => Str::cleanup($signedBy),
            ],
        ]);

        $data = json_decode($response->getBody()->getContents(), true);

        if (! isset($data['url'])) {
            throw new RuntimeException('Failed to sign the URL.');
        }

        return $data['url'];
    }
}
