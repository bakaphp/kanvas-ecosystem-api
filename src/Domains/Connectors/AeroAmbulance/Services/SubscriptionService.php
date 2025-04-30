<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia\Services;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\AeroAmbulance\Client;
use Kanvas\Connectors\AeroAmbulance\Enums\DocumentTypeEnum;
use Kanvas\Connectors\AeroAmbulance\Enums\SubscriptionTypeEnum;
use Kanvas\Exceptions\ValidationException;

class SubscriptionService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
        $this->client = new Client($app, $company);
    }

    /**
     * Create a new subscription item
     */
    public function createNewSubscription(array $data, int $subscriptionId): array
    {
        $this->validateSubscriptionData($data);
        $data['type'] = SubscriptionTypeEnum::NEW->value;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Create a top-up subscription item
     */
    public function createTopUpSubscription(array $data, int $subscriptionId): array
    {
        $this->validateSubscriptionData($data);
        $data['type'] = SubscriptionTypeEnum::TOPUP->value;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Create a relationship subscription item
     */
    public function createRelationshipSubscription(array $data, int $subscriptionId, int $relationshipId): array
    {
        $this->validateSubscriptionData($data);
        $data['type'] = SubscriptionTypeEnum::NEW->value;
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
            'preferredLanguage',
        ];

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        if (! in_array($data['documentType'], [DocumentTypeEnum::CEDULA->value, DocumentTypeEnum::PASAPORTE->value])) {
            throw new ValidationException('Invalid document type');
        }
    }
}
