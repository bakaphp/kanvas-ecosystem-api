<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Services;

use Carbon\Carbon;
use Kanvas\Domains\Connectors\AeroAmbulancia\Client;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\SubscriptionType;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;

class AeroAmbulanciaSubscriptionService extends BaseService
{
    public function __construct(Client $client)
    {
        parent::__construct($client);
    }

    /**
     * Create a new subscription with beneficiaries
     *
     * @param People $people The people record from the order
     * @param array $subscriptionData The subscription data from the order metadata
     * @throws ValidationException
     */
    public function createNewSubscription(People $people, array $subscriptionData): array
    {
        if (! isset($subscriptionData['beneficiaries'])) {
            throw new ValidationException('Missing beneficiaries data in order metadata');
        }

        $beneficiaries = $subscriptionData['beneficiaries'];

        // Create holder subscription
        $holderData = $this->prepareBeneficiaryData($people, $beneficiaries['holder']);
        $holderData['type'] = SubscriptionType::NEW->value;

        $response = $this->client->post("/subscriptions/44219/subscription-items", $holderData);

        // Create dependents subscriptions if they exist
        if (isset($beneficiaries['dependents']) && ! empty($beneficiaries['dependents'])) {
            foreach ($beneficiaries['dependents'] as $dependent) {
                $dependentData = $this->prepareBeneficiaryData($people, $dependent);
                $dependentData['type'] = SubscriptionType::NEW->value;
                $dependentData['relationship'] = $dependent['holderRelationship'];

                $this->client->post("/subscriptions/44219/subscription-items", $dependentData);
            }
        }

        return $response;
    }

    /**
     * Prepare beneficiary data from people and subscription data
     */
    protected function prepareBeneficiaryData(People $people, array $beneficiaryData): array
    {
        $this->validateBeneficiaryData($beneficiaryData);

        // Get the subscription variant to get the days
        $subscriptionVariant = Variants::find($beneficiaryData['ambulanceVariantId']);
        if (! $subscriptionVariant) {
            throw new ValidationException('Invalid ambulanceVariantId: ' . $beneficiaryData['ambulanceVariantId']);
        }

        $days = (int) $subscriptionVariant->getAttributeBySlug('duration')?->value ?? 30; // Default to 30 days if not specified

        // Calculate expiration date based on activation date
        $activationDate = Carbon::createFromFormat('d-m-Y', $beneficiaryData['activationDate']);
        $expirationDate = $activationDate->addDays($days)->format('Y-m-d H:i:s');

        return [
            'documentType' => $beneficiaryData['documentType'],
            'documentNumber' => $beneficiaryData['documentNumber'],
            'firstName' => $beneficiaryData['firstname'],
            'lastName' => $beneficiaryData['lastname'],
            'email' => $people->getEmails()->first()?->value,
            'phoneNumber' => $people->getPhones()->first()?->value,
            'sex' => $beneficiaryData['gender'],
            'birthdate' => $beneficiaryData['birthDate'],
            'activationDate' => $beneficiaryData['activationDate'],
            'expirationDate' => $expirationDate,
            'acquiredPlan' => $beneficiaryData['ambulanceVariantId'],
            'preferredLanguage' => $beneficiaryData['preferredLanguage'] ?? 'es'
        ];
    }

    /**
     * Validate beneficiary data
     *
     * @throws ValidationException
     */
    protected function validateBeneficiaryData(array $data): void
    {
        $requiredFields = [
            'documentType',
            'documentNumber',
            'firstname',
            'lastname',
            'gender',
            'birthDate',
            'activationDate',
            'ambulanceVariantId'
        ];

        foreach ($requiredFields as $field) {
            if (! isset($data[$field])) {
                throw new ValidationException("Missing required field: {$field}");
            }
        }

        $validDocumentTypes = ['passport', 'id'];
        if (! in_array($data['documentType'], $validDocumentTypes)) {
            throw new ValidationException('Invalid document type. Must be either "passport" or "id"');
        }

        $validGenders = ['M', 'F'];
        if (! in_array($data['gender'], $validGenders)) {
            throw new ValidationException('Invalid gender. Must be either "M" or "F"');
        }
    }
}
