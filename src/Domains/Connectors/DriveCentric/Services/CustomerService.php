<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\DriveCentric\Client;
use Kanvas\Connectors\DriveCentric\Exceptions\DriveCentricException;
use Kanvas\Guild\Customers\Models\People;

/**
 * CustomerService handles customer operations in DriveCentric.
 *
 * IMPORTANT: In DriveCentric, customers CANNOT be created independently.
 * Customers are only created when a lead/deal is created. Use LeadService
 * to create leads which will automatically create the associated customer.
 * This service only handles searching and updating existing customers.
 */
class CustomerService
{
    public Client $client;

    public function __construct(
        protected Companies $company,
        protected Apps $app
    ) {
        $this->client = new Client($this->app, $this->company);
    }

    protected const array REQUIRED_SEARCH_FILTERS = ['email', 'phone', 'firstName', 'lastName'];

    /**
     * Search customers with various filters.
     * GET /api/stores/{storeId}/customers
     *
     * @throws DriveCentricException if no search filter is provided
     */
    public function search(array $filters = []): array
    {
        $this->validateSearchFilters($filters);

        $response = $this->client->get('/api/stores/{+storeId}/customers', $filters);

        return $response->json('customers') ?? [];
    }

    /**
     * Validate that at least one search filter is provided.
     *
     * @throws DriveCentricException if no required filter is present
     */
    protected function validateSearchFilters(array $filters): void
    {
        $hasRequiredFilter = false;

        foreach (self::REQUIRED_SEARCH_FILTERS as $filter) {
            if (! empty($filters[$filter])) {
                $hasRequiredFilter = true;

                break;
            }
        }

        if (! $hasRequiredFilter) {
            throw new DriveCentricException(
                'DriveCentric customer search requires at least one filter: ' .
                implode(', ', self::REQUIRED_SEARCH_FILTERS)
            );
        }
    }

    /**
     * Get customers with pagination.
     */
    public function getCustomers(
        int $offset = 0,
        int $limit = 100,
        ?string $email = null,
        ?string $phone = null,
        ?string $firstName = null,
        ?string $lastName = null
    ): array {
        $params = array_filter([
            'offset' => $offset,
            'limit' => $limit,
            'email' => $email,
            'phone' => $phone,
            'firstName' => $firstName,
            'lastName' => $lastName,
        ]);

        return $this->search($params);
    }

    /**
     * Get customer by ID.
     * GET /api/stores/{storeId}/customers/{customerId}
     */
    public function getCustomerById(string $customerId): array
    {
        $response = $this->client->get("/api/stores/{+storeId}/customers/{$customerId}");
        $customer = $response->json('customerInfo');

        if (empty($customer)) {
            throw new DriveCentricException("Customer with ID {$customerId} not found");
        }

        return $customer;
    }

    /**
     * Get customer by email.
     */
    public function getCustomerByEmail(string $email): ?array
    {
        $response = $this->client->get('/api/stores/{+storeId}/customers', [
            'email' => $email,
        ]);

        return $response->json('customers.0');
    }

    /**
     * Get customer by phone.
     */
    public function getCustomerByPhone(string $phone): ?array
    {
        $response = $this->client->get('/api/stores/{+storeId}/customers', [
            'phone' => $phone,
        ]);

        return $response->json('customers.0');
    }

    /**
     * Get customers by deal ID.
     * GET /api/stores/{storeId}/customers/deal/{dealId}
     */
    public function getCustomersByDealId(string $dealId): array
    {
        $response = $this->client->get("/api/stores/{+storeId}/customers/deal/{$dealId}");

        return $response->json('customers') ?? [];
    }

    /**
     * Update an existing customer.
     * POST /api/stores/{storeId}/customers/{customerId}
     *
     * Note: Customers can only be created through leads/deals.
     * Use LeadService to create a lead which will create the customer.
     */
    public function updateCustomer(string $customerId, array $customerData): array
    {
        unset($customerData['identifiers']); // ID should not be part of update payload
        $response = $this->client->post("/api/stores/{+storeId}/customers/{$customerId}", [
            'customer' => $customerData,
        ]);

        return $response->json() ?? [];
    }

    /**
     * Add contact information to customer.
     * POST /api/stores/{storeId}/customers/{customerId}/contact
     */
    public function addCustomerContact(string $customerId, array $contactData): array
    {
        $response = $this->client->post("/api/stores/{+storeId}/customers/{$customerId}/contact", $contactData);

        return $response->json() ?? [];
    }

    /**
     * Add credit application to customer.
     * POST /api/stores/{storeId}/customers/{customerId}/creditApp
     */
    public function addCreditApp(string $customerId, array $creditAppData): array
    {
        $response = $this->client->post("/api/stores/{+storeId}/customers/{$customerId}/creditApp", $creditAppData);

        return $response->json() ?? [];
    }

    /**
     * Convert Kanvas People to DriveCentric customer format.
     */
    public function formatPeopleForDriveCentric(People $people): array
    {
        $phones = $people->phones->map(function ($phone) {
            return [
                'type' => 'Home',
                'value' => $phone->value,
            ];
        })->toArray();

        $emails = $people->emails->map(function ($email) {
            return [
                'type' => 'Home',
                'value' => $email->value,
            ];
        })->toArray();

        $addresses = $people->address()->get()->map(function ($address) {
            return [
                'line1' => $address->address ?? '',
                'line2' => $address->address_2 ?? '',
                'city' => $address->city ?? '',
                'stateOrProvince' => $address->state ?? '',
                'zipOrPostalCode' => $address->zip ?? '',
                'countryCode' => 'US',
                'county' => $address->county ?? '',
            ];
        })->toArray();

        return [
            'firstName' => $people->firstname,
            'middleName' => $people->middlename ?? '',
            'lastName' => $people->lastname,
            'birthdate' => $people->dob,
            'phones' => $phones,
            'emails' => $emails,
            'addresses' => $addresses,
            'type' => 'Individual',
        ];
    }
}
