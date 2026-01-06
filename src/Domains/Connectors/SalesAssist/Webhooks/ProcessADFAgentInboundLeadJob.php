<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Webhooks;

use Kanvas\Connectors\DealerSocket\Actions\PullLeadAction;
use Kanvas\Connectors\DealerSocket\Actions\PullPeopleAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Actions\PullLeadFromADFAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDTO;
use Kanvas\Guild\Leads\Actions\CreateLeadAction;
use Kanvas\Guild\Leads\DataTransferObject\Lead as LeadDTO;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kiwilan\XmlReader\XmlReader;
use Override;
use Spatie\LaravelData\DataCollection;

class ProcessADFAgentInboundLeadJob extends ProcessWebhookJob
{
    #[Override]
    public function execute(): array
    {
        $payload = $this->webhookRequest->payload;
        $app = $this->webhookRequest->receiverWebhook->app;
        $company = $this->webhookRequest->receiverWebhook->company;
        $user = $this->webhookRequest->receiverWebhook->user;
        $configuration = $this->webhookRequest->receiverWebhook->configuration;

        // Parse XML
        $xml = XmlReader::make($payload['body-plain'], true, true);
        $data = $xml->toArray();

        if (! isset($data['adf']['prospect'])) {
            return [
                'status' => 'failed',
                'message' => 'No prospect data found in ADF payload',
            ];
        }

        $prospect = $data['adf']['prospect'];
        $customer = $prospect['customer'] ?? [];
        $contact = $customer['contact'] ?? [];

        // Extract prospect ID and metadata
        $prospectId = $prospect['id']['@content'] ?? null;
        $prospectSequence = $prospect['id']['@attributes']['sequence'] ?? null;
        $prospectSource = $prospect['id']['@attributes']['source'] ?? null;
        $prospectStatus = $prospect['@attributes']['status'] ?? null;
        $requestDate = $prospect['requestdate'] ?? null;

        // Extract customer name
        $names = $contact['name'] ?? [];
        $firstName = null;
        $lastName = null;
        if (is_array($names)) {
            foreach ($names as $name) {
                if (isset($name['@attributes']['part'])) {
                    if ($name['@attributes']['part'] === 'first') {
                        $firstName = $name['@content'] ?? null;
                    } elseif ($name['@attributes']['part'] === 'last') {
                        $lastName = $name['@content'] ?? null;
                    }
                }
            }
        }

        // Extract email safely
        $emailData = $contact['email'] ?? null;
        $email = is_array($emailData) ? ($emailData['@content'] ?? null) : $emailData;

        // Extract phone safely
        $phoneData = $contact['phone'] ?? null;
        $phone = is_array($phoneData) ? ($phoneData['@content'] ?? null) : $phoneData;
        $phoneType = is_array($phoneData) && isset($phoneData['@attributes']['type']) ? $phoneData['@attributes']['type'] : null;

        // Extract address
        $address = $contact['address'] ?? [];
        $street1 = null;
        $street2 = null;
        if (isset($address['street'])) {
            $streets = $address['street'];
            if (is_array($streets)) {
                foreach ($streets as $street) {
                    if (isset($street['@attributes']['line'])) {
                        if ($street['@attributes']['line'] === '1') {
                            $street1 = $street['@content'] ?? null;
                        } elseif ($street['@attributes']['line'] === '2') {
                            $street2 = $street['@content'] ?? null;
                        }
                    }
                }
            }
        }
        $city = $address['city'] ?? null;
        $postalCode = $address['postalcode'] ?? null;

        // Extract comments
        $comments = $customer['comments'] ?? null;

        // Extract vendor information
        $vendor = $prospect['vendor'] ?? [];
        $vendorId = $vendor['id']['@content'] ?? null;
        $vendorIdSource = $vendor['id']['@attributes']['source'] ?? null;
        $vendorName = $vendor['vendorname'] ?? null;

        // Extract provider information
        $provider = $prospect['provider'] ?? [];
        $providerName = $provider['name'] ?? null;
        $providerService = $provider['service'] ?? null;

        // Check if we should create a new lead using native Kanvas creation
        $shouldCreate = isset($payload['create']) && $payload['create'] === true;

        if ($shouldCreate) {
            $lead = $this->createLeadFromADF([
                'firstName' => $firstName,
                'lastName' => $lastName,
                'email' => $email,
                'phone' => $phone,
                'street1' => $street1,
                'street2' => $street2,
                'city' => $city,
                'postalCode' => $postalCode,
                'comments' => $comments,
                'prospectId' => $prospectId,
                'prospectSource' => $prospectSource,
                'vendorName' => $vendorName,
                'providerName' => $providerName,
                'providerService' => $providerService,
            ]);
        } else {
            // First attempt: pull lead directly from ADF
            $existingLead = new PullLeadFromADFAction($this->webhookRequest)->execute();

            // Initialize pull-lead action for reuse
            $pullLeadAction = new PullLeadAction($app, $company, $user);

            // If ADF didn’t provide a lead, look up the person in CRM
            if ($existingLead === null) {
                $people = new PullPeopleAction($app, $company, $user)->execute(
                    email: $email,
                    phoneNumber: $phone
                );

                if ($people === null) {
                    $lead = $this->createLeadFromADF([
                        'firstName' => $firstName,
                        'lastName' => $lastName,
                        'email' => $email,
                        'phone' => $phone,
                        'street1' => $street1,
                        'street2' => $street2,
                        'city' => $city,
                        'postalCode' => $postalCode,
                        'comments' => $comments,
                        'prospectId' => $prospectId,
                        'prospectSource' => $prospectSource,
                        'vendorName' => $vendorName,
                        'providerName' => $providerName,
                        'providerService' => $providerService,
                    ]);
                } else {
                    // Pull/create lead from CRM customer ID
                    $lead = $pullLeadAction->execute(
                        customerId: $people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value),
                        triggerFirstMessage: true
                    );
                }
            } else {
                // Lead exists → refresh/update from CRM
                $lead = $pullLeadAction->execute(
                    customerId: $existingLead->people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value),
                    triggerFirstMessage: true
                );
            }
        }

        return [
            'status' => 'success',
            'message' => 'ADF Agent Inbound Lead Processed',
            'lead_id' => is_object($lead) ? $lead->getId() : $lead,
        ];
    }

    protected function createLeadFromADF(array $data): Lead
    {
        $contacts = [];
        if ($data['email']) {
            $contacts[] = ['contacts_types_id' => 1, 'value' => $data['email']];
        }
        if ($data['phone']) {
            $contacts[] = ['contacts_types_id' => 2, 'value' => $data['phone']];
        }

        $addressData = [];
        if ($data['street1'] || $data['city'] || $data['postalCode']) {
            $addressData[] = [
                'address' => $data['street1'] ?? '',
                'address_2' => $data['street2'] ?? '',
                'city' => $data['city'] ?? '',
                'zip' => $data['postalCode'] ?? '',
            ];
        }

        $peopleDTO = PeopleDTO::from([
            'app' => $this->webhookRequest->receiverWebhook->app,
            'branch' => $this->webhookRequest->receiverWebhook->company->branch,
            'user' => $this->webhookRequest->receiverWebhook->user,
            'firstname' => $data['firstName'] ?? '',
            'lastname' => $data['lastName'] ?? '',
            'contacts' => Contact::collect($contacts, DataCollection::class),
            'address' => Address::collect($addressData, DataCollection::class),
        ]);

        $leadDTO = new LeadDTO(
            app: $this->webhookRequest->receiverWebhook->app,
            branch: $this->webhookRequest->receiverWebhook->company->branch,
            user: $this->webhookRequest->receiverWebhook->user,
            title: ($data['firstName'] ?? '') . ' ' . ($data['lastName'] ?? ''),
            pipeline_stage_id: 0,
            people: $peopleDTO,
            description: $data['comments'],
            custom_fields: [
                'prospect_id' => $data['prospectId'],
                'prospect_source' => $data['prospectSource'],
                'vendor_name' => $data['vendorName'],
                'provider_name' => $data['providerName'],
                'provider_service' => $data['providerService'],
            ]
        );

        return new CreateLeadAction($leadDTO)->execute();
    }
}
