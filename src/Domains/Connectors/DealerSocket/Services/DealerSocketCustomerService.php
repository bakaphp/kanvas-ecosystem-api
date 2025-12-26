<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Kanvas\Connectors\DealerSocket\CustomerClient;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Throwable;

class DealerSocketCustomerService
{
    public CustomerClient $customerClient;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
    ) {
        $this->customerClient = new CustomerClient(
            app: $app,
            company: $company,
        );
    }

    /**
     * Save a customer to DealerSocket
     */
    public function saveCustomer(People $people): array
    {
        $entityId = $people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);

        if ($entityId) {
            return $this->updateCustomer($people);
        }

        $customerData = $this->mapCustomerToArray($people);

        $response = $this->customerClient->createCustomer($customerData);

        if (isset($response['entityId'])) {
            $this->setCustomerId($people, $response['entityId']);
        }

        return $response;
    }

    /**
     * Update a customer in DealerSocket
     */
    public function updateCustomer(People $people): array
    {
        $entityId = $people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);

        if (! $entityId) {
            throw new Exception(
                'Customer does not have a DealerSocket Customer ID (Entity ID). ' .
                'Please create the customer in DealerSocket first.'
            );
        }

        $customerData = $this->mapCustomerToArray($people);

        $response = $this->customerClient->updateCustomer((int) $entityId, $customerData);

        if (! isset($response['success'])) {
            throw new Exception($response['errorMessage'] ?? 'Customer update failed');
        }

        return $response;
    }

    /**
     * Map People model to DealerSocket customer array format
     */
    protected function mapCustomerToArray(People $people): array
    {
        $data = [
            'type' => 'Individual',
        ];

        $data['firstName'] = $people->firstname;
        $data['lastName'] = $people->lastname;

        // Email (required)
        $email = $this->getEmailFromPeople($people);
        if ($email) {
            $data['email'] = $email;
        }

        // Phone (optional)
        $phone = $this->getPhoneFromPeople($people);
        if ($phone) {
            $data['phone'] = $phone;
        }

        return $data;
    }

    /**
     * Get email from People model
     */
    protected function getEmailFromPeople(People $people): ?string
    {
        try {
            $emails = $people->getEmails();

            if ($emails->isEmpty()) {
                return null;
            }

            return $emails->first()->value;
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Get phone from People model
     */
    protected function getPhoneFromPeople(People $people): ?string
    {
        try {
            $phones = $people->getAllPhones();

            if ($phones->isEmpty()) {
                return null;
            }

            $phone = $phones->first()->value;

            return $this->formatPhone($phone);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * Format phone number (remove non-numeric characters)
     */
    protected function formatPhone(string $phone): string
    {
        return preg_replace('/[^0-9]/', '', $phone);
    }

    /**
     * Set Customer ID (Entity ID) in People custom fields
     */
    public function setCustomerId(People $people, string $customerId): void
    {
        $people->set(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value, $customerId);
    }

    /**
     * Search for a customer by email
     */
    public function searchCustomerByEmail(string $email): array
    {
        return $this->customerClient->getCustomerByEmail($email);
    }

    /**
     * Search for a customer by phone
     */
    public function searchCustomerByPhone(string $phone): array
    {
        $formattedPhone = $this->formatPhone($phone);

        return $this->customerClient->getCustomerByPhone($formattedPhone);
    }

    /**
     * Search for a customer by name
     */
    public function searchCustomerByName(?string $firstName, ?string $lastName): array
    {
        return $this->customerClient->getCustomerByName($firstName, $lastName);
    }

    /**
     * Get customer by Entity ID
     */
    public function getCustomerById(string|int $entityId): array
    {
        return $this->customerClient->getCustomerById($entityId);
    }

    /**
     * Create or update customer (upsert logic)
     */
    public function saveOrUpdateCustomer(People $people): array
    {
        // Check if customer already has an Entity ID
        $entityId = $people->get(CustomFieldEnum::DEALER_SOCKET_CUSTOMER_ID->value);

        if ($entityId) {
            return $this->updateCustomer($people);
        }

        // Try to find existing customer by email
        $email = $this->getEmailFromPeople($people);
        if ($email) {
            $searchResults = $this->searchCustomerByEmail($email);

            if (! empty($searchResults['entityId'])) {
                $this->setCustomerId($people, $searchResults['entityId']);

                return $this->updateCustomer($people);
            }
        }

        return $this->saveCustomer($people);
    }
}
