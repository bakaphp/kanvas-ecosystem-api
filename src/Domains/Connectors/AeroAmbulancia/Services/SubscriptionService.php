<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Services;

use Kanvas\Domains\Connectors\AeroAmbulancia\Client;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\DocumentType;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\SubscriptionType;
use Kanvas\Exceptions\ValidationException;
use Exception;

class SubscriptionService extends BaseService
{
    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    /**
     * Create a new subscription item
     *
     * @param array $data
     * @param int $subscriptionId
     * @return array
     * @throws Exception
     */
    public function createNewSubscription(array $data, int $subscriptionId): array
    {
        try {
            $this->validateSubscriptionData($data);
            $data['type'] = SubscriptionType::NEW->value;

            return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Create a top-up subscription item
     *
     * @param array $data
     * @param int $subscriptionId
     * @return array
     * @throws Exception
     */
    public function createTopUpSubscription(array $data, int $subscriptionId): array
    {
        try {
            $this->validateSubscriptionData($data);
            $data['type'] = SubscriptionType::TOPUP->value;

            return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Create a relationship subscription item
     *
     * @param array $data
     * @param int $subscriptionId
     * @param int $relationshipId
     * @return array
     * @throws Exception
     */
    public function createRelationshipSubscription(array $data, int $subscriptionId, int $relationshipId): array
    {
        try {
            $this->validateSubscriptionData($data);
            $data['type'] = SubscriptionType::NEW->value;
            $data['relationship'] = $relationshipId;

            return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
    }

    /**
     * Validate subscription data
     *
     * @param array $data
     * @return void
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
            if (!isset($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        if (!in_array($data['documentType'], [DocumentType::CEDULA->value, DocumentType::PASAPORTE->value])) {
            throw new ValidationException('Invalid document type');
        }
    }
}
