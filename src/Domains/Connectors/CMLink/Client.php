<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CMLink;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\CMLink\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected string $baseUri;
    protected string $appKey;
    protected string $appSecret;
    protected string $appId;
    protected string|int $appType;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->baseUri = $this->app->get(ConfigurationEnum::BASE_URL->value);
        $this->appKey = $this->app->get(ConfigurationEnum::APP_KEY->value);
        $this->appSecret = $this->app->get(ConfigurationEnum::APP_SECRET->value);
        $this->appId = $this->app->get(ConfigurationEnum::APP_KEY->value);
        $this->appType = $this->app->get(ConfigurationEnum::APP_TYPE->value);

        if (empty($this->baseUri) || empty($this->appKey) || empty($this->appSecret)) {
            throw new ValidationException('CM Link configuration is missing');
        }

        $this->client = new GuzzleClient([
            'base_uri' => $this->baseUri,
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    protected function generateWsseHeader(): array
    {
        $nonce = bin2hex(random_bytes(16));
        $created = gmdate('Y-m-d\TH:i:s\Z');
        $passwordDigest = base64_encode(hash('sha256', $nonce . $created . $this->appSecret, true));

        return [
            'Authorization' => 'WSSE realm="SDP", profile="UsernameToken", type="Appkey"',
            'X-WSSE' => sprintf(
                'UsernameToken Username="%s", PasswordDigest="%s", Nonce="%s", Created="%s"',
                $this->appKey,
                $passwordDigest,
                $nonce,
                $created
            ),
        ];
    }

    public function getAccessToken(): string
    {
        return $this->post('/aep/APP_getAccessToken_SBO/v1', [
            'id' => $this->appId,
            'type' => $this->appType,
        ])['accessToken'];
    }

    public function request($method, $uri, $body = []): array
    {
        $headers = $this->generateWsseHeader($this->appKey, $this->appSecret);

        $response = $this->client->request($method, $uri, [
            'headers' => $headers,
            'json' => $body,
        ]);

        return json_decode($response->getBody()->getContents(), true);
    }

    public function get($uri, $body = []): array
    {
        return $this->request('GET', $uri, $body);
    }

    public function post($uri, $body = []): array
    {
        return $this->request('POST', $uri, $body);
    }
}
