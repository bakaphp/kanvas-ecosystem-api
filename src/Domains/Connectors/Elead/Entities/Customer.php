<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Entities;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Exceptions\ELeadException;
use Kanvas\Connectors\Elead\Support\EleadCache;
use Kanvas\Guild\Customers\Models\People;

class Customer
{
    public ?string $id = null;
    public bool $isBusiness = false;
    public ?string $title = null;
    public ?string $firstName = null;
    public ?string $middleName = null;
    public ?string $lastName = null;
    public ?string $nickname = null;
    public ?string $birthday = null;
    public ?string $businessName = null;
    public array $emails = [];
    public array $phones = [];
    public array $address = [];
    public ?Companies $company = null;
    public ?AppInterface $app = null;

    /**
     * Assign value to the current object.
     */
    public function assign(array $data): void
    {
        foreach ($data as $key => $value) {
            $this->{$key} = $value;
        }
    }

    /**
     * Sanitize payload by removing null values and empty arrays.
     * Fortellis API rejects requests with empty arrays or null values.
     */
    private static function sanitizePayload(array $data): array
    {
        return array_filter($data, function ($value) {
            // Remove null values
            if ($value === null) {
                return false;
            }
            // Remove empty arrays
            if (is_array($value) && empty($value)) {
                return false;
            }

            return true;
        });
    }

    public static function create(AppInterface $app, Companies $company, array $data): self
    {
        $client = new Client($app, $company);
        $response = $client->post(
            '/sales/v1/elead/customers/',
            self::sanitizePayload($data),
        );

        if (isset($response['code']) && $response['message']) {
            throw new ELeadException($response['message']);
        }

        $newCustomer = new Customer();
        $newCustomer->company = $company;
        $newCustomer->app = $app;
        $newCustomer->assign($response);

        return $newCustomer;
    }

    public function update(array $data): self
    {
        $client = new Client($this->app, $this->company);
        $response = $client->post(
            '/sales/v1/elead/customers/' . $this->id,
            self::sanitizePayload($data),
        );

        if (isset($response['code']) && $response['message']) {
            throw new ELeadException($response['message']);
        }

        if ($this->id !== null) {
            $cache = new EleadCache($this->app, $this->company);
            $cache->invalidate('customer', $this->id);
        }

        $this->assign($response);

        return $this;
    }

    public static function getById(AppInterface $app, Companies $company, string $id, bool $fresh = false): self
    {
        $cache = new EleadCache($app, $company);

        if (! $fresh) {
            $cached = $cache->get('customer', $id);

            if ($cached !== null) {
                $customer = new Customer();
                $customer->app = $app;
                $customer->company = $company;
                $customer->assign($cached);

                return $customer;
            }
        }

        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v1/elead/customers/' . $id,
        );

        $cache->set('customer', $id, $response);

        $customer = new Customer();
        $customer->app = $app;
        $customer->company = $company;
        $customer->assign($response);

        return $customer;
    }

    /**
     * Get the customer by a people reference.
     */
    public static function getByPeople(People $people): self
    {
        $customerId = $people->get(CustomFieldEnum::CUSTOMER_ID->value);
        if (empty($customerId)) {
            throw new ELeadException('This Customer doesn\'t have a reference in ELeads');
        }

        return self::getById(
            $people->app,
            $people->company,
            $customerId
        );
    }

    public function getOwnedVehicles(): array
    {
        $client = new Client($this->app, $this->company);
        $response = $client->get(
            '/sales/v1/elead/customers/' . $this->id . '/ownedVehicles',
        );

        return $response;
    }

    public function search(array $criteria, int $page = 1, int $limit = 25): array
    {
        $phone = $criteria['phoneNumber'] ?? '';
        $email = $criteria['emailAddress'] ?? '';
        $firstName = $criteria['firstName'] ?? '';
        $lastName = $criteria['lastName'] ?? '';

        if (empty($phone) && empty($email) && empty($firstName) && empty($lastName)) {
            return [];
        }

        $searchCriteria = [];
        $searchCriteria['phoneNumber'] = $phone;
        $searchCriteria['emailAddress'] = $email;
        $searchCriteria['firstName'] = $firstName;
        $searchCriteria['lastName'] = $lastName;

        $client = new Client($this->app, $this->company);
        $response = $client->post(
            '/sales/v1/elead/customers/search?pageSize=' . $limit . '&page=' . $page,
            $searchCriteria
        );

        return $response;
    }
}
