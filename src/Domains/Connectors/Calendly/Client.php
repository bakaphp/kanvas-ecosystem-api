<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Calendly;

use Baka\Contracts\CompanyInterface;
use GuzzleHttp\Client as GuzzleClient;
use Kanvas\Connectors\Calendly\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;

class Client
{
    protected GuzzleClient $httpClient;
    protected string $baseUrl = 'https://api.calendly.com';

    public function __construct(
        protected CompanyInterface $company
    ) {
        $apiToken = $this->company->get(ConfigurationEnum::CALENDLY_API_TOKEN->value);

        if (empty($apiToken)) {
            throw new ValidationException('Calendly API token is not configured for company: ' . $this->company->name);
        }

        $this->httpClient = new GuzzleClient([
            'timeout' => 15,
            'headers' => [
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => 'Bearer ' . $apiToken,
            ],
        ]);
    }

    public function get(string $path, array $query = []): array
    {
        $response = $this->httpClient->get("{$this->baseUrl}{$path}", [
            'query' => $query,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    public function post(string $path, array $data = []): array
    {
        $response = $this->httpClient->post("{$this->baseUrl}{$path}", [
            'json' => $data,
        ]);

        return json_decode($response->getBody()->getContents(), true) ?? [];
    }

    public function delete(string $uri): void
    {
        $this->httpClient->delete($uri);
    }

    public function getCurrentUser(): array
    {
        return $this->get('/users/me');
    }

    public function createWebhookSubscription(
        string $callbackUrl,
        array $events,
        string $organizationUri,
        string $userUri,
        string $scope = 'organization'
    ): array {
        return $this->post('/webhook_subscriptions', [
            'url' => $callbackUrl,
            'events' => $events,
            'organization' => $organizationUri,
            'user' => $userUri,
            'scope' => $scope,
        ]);
    }

    public function deleteWebhookSubscription(string $webhookUri): void
    {
        $this->delete($webhookUri);
    }
}
