<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Services;

use Kanvas\Domains\Connectors\AeroAmbulancia\Client;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\DocumentType;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\SubscriptionType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Exceptions\ValidationException;

class AeroAmbulanciaSubscriptionService extends BaseService
{
    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    /**
     * Create a new subscription item
     */
    public function createNewSubscription(People $people, int $subscriptionId, array $subscriptionData): array
    {
        $data = $this->prepareSubscriptionData($people, $subscriptionData);
        $data['type'] = SubscriptionType::NEW->value;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Create a top-up subscription item
     */
    public function createTopUpSubscription(People $people, int $subscriptionId, array $subscriptionData): array
    {
        $data = $this->prepareSubscriptionData($people, $subscriptionData);
        $data['type'] = SubscriptionType::TOPUP->value;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Create a relationship subscription item
     */
    public function createRelationshipSubscription(People $people, int $subscriptionId, int $relationshipId, array $subscriptionData): array
    {
        $data = $this->prepareSubscriptionData($people, $subscriptionData);
        $data['type'] = SubscriptionType::NEW->value;
        $data['relationship'] = $relationshipId;

        return $this->client->post("/subscriptions/{$subscriptionId}/subscription-items", $data);
    }

    /**
     * Prepare subscription data from people and subscription data
     */
    protected function prepareSubscriptionData(People $people, array $subscriptionData): array
    {
        $this->validateSubscriptionData($subscriptionData);

        return [
            'documentType' => $subscriptionData['documentType'],
            'documentNumber' => $subscriptionData['documentNumber'],
            'firstName' => $people->firstname,
            'lastName' => $people->lastname,
            'email' => $people->getEmails()->first()?->value,
            'phoneNumber' => $people->getPhones()->first()?->value,
            'sex' => $subscriptionData['sex'],
            'birthdate' => $subscriptionData['birthdate'],
            'activationDate' => $subscriptionData['activationDate'],
            'expirationDate' => $subscriptionData['expirationDate'],
            'acquiredPlan' => $subscriptionData['acquiredPlan'],
            'preferredLanguage' => $subscriptionData['preferredLanguage']
        ];
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
