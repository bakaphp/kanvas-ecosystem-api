<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Facebook;

use Baka\Contracts\AppInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Facebook\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $client;
    protected string $graphVersion;

    public function __construct(protected AppInterface $app)
    {
        $this->graphVersion = (string) ($this->app->get(ConfigurationEnum::GRAPH_API_VERSION->value) ?? 'v21.0');

        $this->client = new GuzzleClient([
            'base_uri' => 'https://graph.facebook.com/' . $this->graphVersion . '/',
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
        ]);
    }

    public static function exchangeCodeForToken(
        string $appId,
        string $appSecret,
        string $redirectUri,
        string $code,
        string $graphVersion = 'v21.0'
    ): string {
        $client = new GuzzleClient();

        $response = $client->get("https://graph.facebook.com/{$graphVersion}/oauth/access_token", [
            'query' => [
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'redirect_uri' => $redirectUri,
                'code' => $code,
            ],
        ]);

        /** @var array{access_token?: string} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        if (! isset($data['access_token']) || $data['access_token'] === '') {
            throw new ValidationException('Failed to exchange code for Facebook access token');
        }

        return $data['access_token'];
    }

    public static function exchangeLongLivedToken(
        string $appId,
        string $appSecret,
        string $shortLivedToken,
        string $graphVersion = 'v21.0'
    ): string {
        $client = new GuzzleClient();

        $response = $client->get("https://graph.facebook.com/{$graphVersion}/oauth/access_token", [
            'query' => [
                'grant_type' => 'fb_exchange_token',
                'client_id' => $appId,
                'client_secret' => $appSecret,
                'fb_exchange_token' => $shortLivedToken,
            ],
        ]);

        /** @var array{access_token?: string} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        if (! isset($data['access_token']) || $data['access_token'] === '') {
            throw new ValidationException('Failed to exchange for long-lived Facebook token');
        }

        return $data['access_token'];
    }

    public static function getUserPages(
        string $userAccessToken,
        string $graphVersion = 'v21.0'
    ): array {
        $client = new GuzzleClient();

        $response = $client->get("https://graph.facebook.com/{$graphVersion}/me/accounts", [
            'query' => [
                'access_token' => $userAccessToken,
                'fields' => 'id,name,access_token',
            ],
        ]);

        /** @var array{data?: list<array{id: string, name: string, access_token: string}>} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['data'] ?? [];
    }

    public static function subscribePageToLeadgen(
        string $pageId,
        string $pageAccessToken,
        string $graphVersion = 'v21.0'
    ): bool {
        $client = new GuzzleClient();

        $response = $client->post("https://graph.facebook.com/{$graphVersion}/{$pageId}/subscribed_apps", [
            'query' => [
                'access_token' => $pageAccessToken,
                'subscribed_fields' => 'leadgen',
            ],
        ]);

        /** @var array{success?: bool} $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return $data['success'] ?? false;
    }

    public static function getLeadData(
        string $leadId,
        string $pageAccessToken,
        string $graphVersion = 'v21.0'
    ): array {
        $client = new GuzzleClient();

        $response = $client->get("https://graph.facebook.com/{$graphVersion}/{$leadId}", [
            'query' => [
                'access_token' => $pageAccessToken,
                'fields' => 'id,created_time,field_data,form_id,ad_id,ad_name,campaign_id,campaign_name',
            ],
        ]);

        /** @var array<string, mixed> $data */
        $data = json_decode($response->getBody()->getContents(), true);

        return $data;
    }
}
