<?php

namespace Kanvas\Connectors\VinSolution\Services;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\VinSolution\Client;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\EventClient;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Models\ReceiverWebhook;

class EventService
{
    private Client $client;

    public function __construct(
        private readonly AppInterface $app,
        private readonly Companies $company,
        private readonly UserInterface $user
    ) {
        $dealerId = $this->getDealerIdFromCompany();
        $userId = $this->getUserIdFromCompany();

        $this->client = new EventClient($dealerId, $userId, $this->app);
    }

    private function getDealerIdFromCompany(): int
    {
        $dealerId = $this->company->get(CustomFieldEnum::COMPANY->value);

        if (! $dealerId) {
            throw new VinSolutionException('Dealer ID not found in company configuration');
        }

        return (int) $dealerId;
    }

    private function getUserIdFromCompany(): int
    {
        // Cast to specific Companies model as required by ConfigurationEnum::getUserKey
        $userId = $this->user->get(ConfigurationEnum::getUserKey($this->company, $this->company->user));

        if (! $userId) {
            throw new VinSolutionException('User ID not found in user configuration');
        }

        return (int) $userId;
    }

    /**
     * Register your app as an event subscriber with API Key auth
     */
    public function registerSubscriber(ReceiverWebhook $webhook): bool
    {
        $payload = [
            'authorizationType' => 'apiKey',
            'invocationEndpoint' => $webhook->getUrl(),  // Your webhook endpoint
            'httpMethod' => 'post',
            'username' => 'X-Kanvas-App', // Header name for API key
            'password' => $this->app->key,
            'status' => $webhook->is_active ? 'active' : 'inactive',
            'description' => $webhook->description,
            'rateLimit' => [
                'period' => $webhook->configuration['rate_limit_period'] ?? 'second',
                'limit' => $webhook->configuration['rate_limit'] ?? 100,
            ],
        ];

        $json = json_encode($payload);
        if ($json === false) {
            throw new ValidationException('Failed to encode payload as JSON');
        }
        $this->client->post('/vinsolutions/eventingapi/subscriber', $json, [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return true;
    }

    /**
     * Get current subscriber configuration
     */
    public function getSubscriber(): array
    {
        return $this->client->get('/subscriber');
    }

    /**
     * Update subscriber configuration
     */
    public function updateSubscriber(ReceiverWebhook $webhook, array $config = []): bool
    {
        $payload = [
            'authorizationType' => 'apiKey',
            'invocationEndpoint' => $webhook->getUrl(),
            'httpMethod' => 'post',
            'username' => 'X-Kanvas-App',
            'password' => $this->app->key,
            'status' => $webhook->is_active ? 'active' : 'inactive',
             'rateLimit' => [
                'period' => $webhook->configuration['rate_limit_period'] ?? 'second',
                'limit' => $webhook->configuration['rate_limit'] ?? 100,
            ],
        ];

        $json = json_encode($payload);
        if ($json === false) {
            throw new ValidationException('Failed to encode payload as JSON');
        }

        $this->client->put('/subscriber', $json, [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return true;
    }

    /**
     * Get current subscriptions
     */
    public function getSubscriptions(int $limit = 100): array
    {
        return $this->client->get('/subscription', [
            'query' => [
                'limit' => min($limit, 100), // Max 100 per spec
            ],
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);
    }

    /**
     * Update subscriptions for a dealer
     */
    public function updateSubscriptions(int $dealerId, array $subscriptions, string $status = 'active'): bool
    {
        // Validate subscriptions
        $validSubscriptions = array_intersect($subscriptions, self::availableSubscriptions());

        if (empty($validSubscriptions)) {
            return false;
        }

        $payload = [
            'dealerId' => $dealerId,
            'status' => $status,
            'subscriptions' => $validSubscriptions,
        ];

        $json = json_encode($payload);
        if ($json === false) {
            throw new ValidationException('Failed to encode payload as JSON');
        }

        $this->client->put("/subscription/dealerId/{$dealerId}", $json, [
            'headers' => [
                'Accept' => 'application/vnd.coxauto.v1+json',
            ],
        ]);

        return true;
    }

    /**
     * Get all available subscription types
     */
    public static function availableSubscriptions(): array
    {
        return [
            'AppointmentUpdated',
            'ConsentUpdated',
            'CustomerCreated',
            'CustomerDeactivated',
            'CustomerMerged',
            'CustomerUpdated',
            'LastContactAttemptUpdated',
            'LeadCreated',
            'LeadUpdated',
            'ShowroomVisitCompleted',
            'VehicleOfInterestCreated',
            'VehicleOfInterestUpdated',
        ];
    }
}
