<?php

declare(strict_types=1);

namespace Kanvas\Domains\Connectors\AeroAmbulancia\Services;

use Carbon\Carbon;

use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Domains\Connectors\AeroAmbulancia\Client;
use Kanvas\Domains\Connectors\AeroAmbulancia\Enums\SubscriptionType;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Models\Message;

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
        $subscriptionResponses = [];

        // Create holder subscription
        $holderData = $this->prepareBeneficiaryData($people, $beneficiaries['holder']);
        $holderData['type'] = SubscriptionType::NEW->value;

        $holderResponse = $this->client->post("/subscriptions/44219/subscription-items", $holderData);
        $subscriptionResponses['holder'] = [
            'data' => $holderData,
            'subscriptionItemId' => $holderResponse['id'] ?? null
        ];

        // Create dependents subscriptions if they exist
        if (isset($beneficiaries['dependents']) && ! empty($beneficiaries['dependents'])) {
            $subscriptionResponses['dependents'] = [];
            foreach ($beneficiaries['dependents'] as $dependent) {
                $dependentData = $this->prepareBeneficiaryData($people, $dependent);
                $dependentData['type'] = SubscriptionType::NEW->value;
                $dependentData['relationship'] = $dependent['holderRelationship'];

                $dependentResponse = $this->client->post("/subscriptions/44219/subscription-items", $dependentData);
                $subscriptionResponses['dependents'][] = [
                    'data' => $dependentData,
                    'subscriptionItemId' => $dependentResponse['id'] ?? null
                ];
            }
        }

        // Update the message with AeroAmbulancia data
        if (isset($subscriptionData['order'])) {
            $order = $subscriptionData['order'];
            $messageId = $order->get(CustomFieldEnum::MESSAGE_ESIM_ID->value);

            if ($messageId) {
                $message = Message::getById($messageId);
                $messageData = $message->message;
                $messageData['aeroAmbulanciaData'] = $subscriptionResponses;
                $message->message = $messageData;
                $message->saveOrFail();
            }
            // Update order metadata as well
            $order->metadata = array_merge(($order->metadata ?? []), ['aeroAmbulanciaData' => $subscriptionResponses]);
            $order->saveOrFail();
        }

        return $subscriptionResponses;
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
