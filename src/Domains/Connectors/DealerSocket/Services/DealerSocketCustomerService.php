<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DealerSocket\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Exception;
use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\DealerSocket\CustomerClient;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Regions\Models\Regions;

use function Sentry\captureException;

use Throwable;

class DealerSocketCustomerService
{
    protected CustomerClient $customerClient;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
    ) {
        $this->customerClient = new CustomerClient(app: $app, company: $company, region: $region);
    }

    /**
     * Save a customer to DealerSocket
     */
    public function saveCustomer(People $people): array
    {
        try {
            $customerData = $this->mapCustomerToArray($people);

            $response = $this->customerClient->createCustomer($customerData);

            if (isset($response['entityId'])) {
                $this->setCustomerId($people, $response['entityId']);
            }

            return $response;
        } catch (Throwable $e) {
            Log::error('Failed to create DealerSocket customer', [
                'people_id' => $people->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            captureException($e);

            throw $e;
        }
    }

    /**
     * Update a customer in DealerSocket
     */
    public function updateCustomer(People $people): array
    {
        try {
            $entityId = $people->get(DealerSocketConfigurationService::getCustomerIdKey($people, $this->region));

            if (! $entityId) {
                throw new Exception(
                    'Customer does not have a DealerSocket Customer ID (Entity ID). ' .
                    'Please create the customer in DealerSocket first.'
                );
            }

            $customerData = $this->mapCustomerToArray($people);

            $response = $this->customerClient->updateCustomer((int) $entityId, $customerData);

            if ($response['success'] ?? false) {
                Log::info('Successfully updated DealerSocket customer', [
                    'people_id' => $people->id,
                    'entity_id' => $entityId,
                ]);
            } else {
                throw new Exception($response['errorMessage'] ?? 'Customer update failed');
            }

            return $response;
        } catch (Throwable $e) {
            captureException($e);

            throw $e;
        }
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
                Log::warning('People has no email address', [
                    'people_id' => $people->id,
                ]);

                return null;
            }

            return $emails->first()->value;
        } catch (Throwable $e) {
            Log::warning('Failed to get email from People', [
                'people_id' => $people->id,
                'error' => $e->getMessage(),
            ]);

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
            Log::warning('Failed to get phone from People', [
                'people_id' => $people->id,
                'error' => $e->getMessage(),
            ]);

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
        $people->set(DealerSocketConfigurationService::getCustomerIdKey($people, $this->region), $customerId);
    }

    /**
     * Search for a customer by email
     */
    public function searchCustomerByEmail(string $email): array
    {
        try {
            return $this->customerClient->getCustomerByEmail($email);
        } catch (Throwable $e) {
            Log::error('Failed to search customer by email', [
                'email' => $email,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Search for a customer by phone
     */
    public function searchCustomerByPhone(string $phone): array
    {
        try {
            $formattedPhone = $this->formatPhone($phone);

            return $this->customerClient->getCustomerByPhone($formattedPhone);
        } catch (Throwable $e) {
            Log::error('Failed to search customer by phone', [
                'phone' => $phone,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Search for a customer by name
     */
    public function searchCustomerByName(?string $firstName, ?string $lastName): array
    {
        try {
            return $this->customerClient->getCustomerByName($firstName, $lastName);
        } catch (Throwable $e) {
            Log::error('Failed to search customer by name', [
                'firstName' => $firstName,
                'lastName' => $lastName,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Get customer by Entity ID
     */
    public function getCustomerById(int $entityId): array
    {
        try {
            return $this->customerClient->getCustomerById($entityId);
        } catch (Throwable $e) {
            Log::error('Failed to get customer by ID', [
                'entity_id' => $entityId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Create or update customer (upsert logic)
     */
    public function saveOrUpdateCustomer(People $people): array
    {
        // Check if customer already has an Entity ID
        $entityId = $people->get(DealerSocketConfigurationService::getCustomerIdKey($people, $this->region));

        if ($entityId) {
            return $this->updateCustomer($people);
        }

        // Try to find existing customer by email
        $email = $this->getEmailFromPeople($people);
        if ($email) {
            try {
                $searchResults = $this->searchCustomerByEmail($email);

                if (! empty($searchResults['entityId'])) {
                    $this->setCustomerId($people, $searchResults['entityId']);

                    return $this->updateCustomer($people);
                }
            } catch (Throwable $e) {
                Log::debug('Customer not found by email, will create new', [
                    'email' => $email,
                ]);
            }
        }

        return $this->saveCustomer($people);
    }
}
