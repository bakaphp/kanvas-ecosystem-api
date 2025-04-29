<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Services;

use Kanvas\Domains\Connectors\AeroAmbulancia\Client;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\DocumentType;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\SubscriptionType;
use Kanvas\Exceptions\ValidationException;

class SubscriptionService extends BaseService
{
    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    /**
     * Create a new subscription item
     */
    public function createNewSubscription(array $data, int $subscriptionId): array
    {
        $this->validateSubscriptionData($data);
        $data['type'] = SubscriptionType::NEW->value;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Create a top-up subscription item
     */
    public function createTopUpSubscription(array $data, int $subscriptionId): array
    {
        $this->validateSubscriptionData($data);
        $data['type'] = SubscriptionType::TOPUP->value;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Create a relationship subscription item
     */
    public function createRelationshipSubscription(array $data, int $subscriptionId, int $relationshipId): array
    {
        $this->validateSubscriptionData($data);
        $data['type'] = SubscriptionType::NEW->value;
        $data['relationship'] = $relationshipId;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Validate subscription data
     *
     * @throws ValidationException
     */
    protected function validateSubscriptionData(array $data): void
    {
        $requiredFields = [
            'documentType',
            'documentNumber',
            'firstName',
            'lastName',
            'email',
            'phoneNumber',
            'sex',
            'birthdate',
            'activationDate',
            'expirationDate',
            'acquiredPlan',
            'preferredLanguage'
        ];

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        if (! in_array($data['documentType'], [DocumentType::CEDULA->value, DocumentType::PASAPORTE->value])) {
            throw new ValidationException('Invalid document type');
        }
    }
}
