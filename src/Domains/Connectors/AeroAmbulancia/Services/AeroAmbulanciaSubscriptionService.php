<?php

declare(strict_types=1);

namespace Kanvas\Connectors\AeroAmbulancia\Services;

use Baka\Contracts\AppInterface;
use Carbon\Carbon;
use Kanvas\Connectors\AeroAmbulancia\Client;
use Kanvas\Connectors\AeroAmbulancia\Enums\ConfigurationEnum;
use Kanvas\Connectors\AeroAmbulancia\Enums\SubscriptionType;
use Kanvas\Connectors\ESim\Enums\CustomFieldEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Social\Messages\Models\Message;
use Kanvas\Souk\Orders\Models\Order;

class AeroAmbulanciaSubscriptionService
{
    protected Client $client;

    public function __construct(
        protected AppInterface $app,
        protected Order $order
    ) {
        $this->client = new Client($app, $order->company);
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
        $subscriptionId = $this->app->get(ConfigurationEnum::SUBSCRIPTION_ID->value) ?? 44219;

        $holderResponse = $this->client->post('/subscriptions/' . $subscriptionId . '/subscription-items', $holderData);
        $holderData['status'] = 'active';
        $subscriptionResponses['holder'] = [
            'data' => $holderData,
            'subscriptionItemId' => $holderResponse['id'] ?? null,
        ];

        // Create dependents subscriptions if they exist
        if (isset($beneficiaries['dependents']) && ! empty($beneficiaries['dependents'])) {
            $subscriptionResponses['dependents'] = [];
            foreach ($beneficiaries['dependents'] as $dependent) {
                $dependentData = $this->prepareBeneficiaryData($people, $dependent);
                $dependentData['type'] = SubscriptionType::NEW->value;
                //$dependentData['status'] = 'active';
                $dependentData['relationship'] = (int) $dependent['holderRelationship'];

                $dependentResponse = $this->client->post('/subscriptions/' . $subscriptionId . '/subscription-items', $dependentData);

                $dependentData['status'] = 'active';
                $subscriptionResponses['dependents'][] = [
                    'data' => $dependentData,
                    'subscriptionItemId' => $dependentResponse['id'] ?? null,
                ];
            }
        }

        // Update the message with AeroAmbulancia data

        $order = $this->order;
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
        $acquiredPlan = $subscriptionVariant->getAttributeBySlug('aero_acquired_plan')?->value ?? 1; // Default to basic plan if not specified

        // Calculate expiration date based on activation date
        $activationDate = Carbon::createFromFormat('d-m-Y', $beneficiaryData['activationDate']);
        $expirationDate = $activationDate->addDays($days)->format('Y-m-d H:i:s');

        $typeId = ['passport' => '2', 'id' => '1'];

        return [
            'documentType' => $typeId[$beneficiaryData['documentType']],
            'documentNumber' => $beneficiaryData['documentNumber'],
            'firstName' => $beneficiaryData['firstname'],
            'lastName' => $beneficiaryData['lastname'],
            'email' => $people->getEmails()->first()?->value,
            'phoneNumber' => $people->getPhones()->first()?->value ?? '809732' . sprintf('%04d', random_int(0, 9999)), // Default to a random number if not specified
            'sex' => $beneficiaryData['gender'],
            'birthdate' => $beneficiaryData['birthDate'],
            'activationDate' => $activationDate->format('Y-m-d'),
            'expirationDate' => $expirationDate,
            'acquiredPlan' => (int) $acquiredPlan,
            'preferredLanguage' => ucfirst($beneficiaryData['preferredLanguage'] ?? 'es'),
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
            'ambulanceVariantId',
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
