<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Baka\Validations\Date;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Client;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use RuntimeException;

class Customer
{
    public string $id;
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
     * Convert people into customer.
     */
    public static function convertPeopleToCustomerStructure(People $people): array
    {
        $name = $people->getFirstAndLastName();

        $words = explode(' ', $name['lastName']);
        $middleName = null;

        if (count($words) >= 2) {
            $name['lastName'] = implode(' ', array_slice($words, 1));
            $middleName = $words[0];
        }

        $customerData = [
            'isBusiness' => false,
            'firstName' => $name['firstName'],
            'lastName' => $name['lastName'],
            'middleName' => $name['middleName'] ?? ($middleName ?? ''),
            'birthday' => Date::isValid($people->dob) ? $people->dob : null,
            'emails' => [],
            'phones' => [],
            'address' => [],
        ];

        if (empty($customerData['lastName'])) {
            $customerData['lastName'] = '-';
        }

        $emailCount = 0;
        if ($people->getEmails()->count()) {
            foreach ($people->getEmails() as $email) {
                if ($emailCount == 0) {
                    $customerData['emails'][] = [
                        'address' => filter_var($email->value, FILTER_VALIDATE_EMAIL) ? $email->value : preg_replace("/\s+/", '', Str::cleanup($name['firstName'] . $name['lastName']) . '@salesassist.io'),
                        'emailType' => 'Personal',
                    ];
                    $emailCount++;
                }
            }
        } else {
            //unset($customerData['emails']);
            $customerData['emails'][] = [
                //remove any whitespace
                'address' => preg_replace("/\s+/", '', Str::cleanup($name['firstName'] . $name['lastName']) . '@salesassist.io'),
                'emailType' => 'Personal',
            ];

            $people->saveEmail($customerData['emails'][0]['address']);
        }

        $phoneCount = 0;
        $phoneExist = [];
        $peoplePhones = $people->getCellPhone()->count() ? $people->getCellPhone() : $people->getPhones();
        if ($peoplePhones->count()) {
            foreach ($peoplePhones as $phone) {
                if (! Str::contains($phone->value, '800')
                    && ! Str::contains($phone->value, '888')
                    && ! Str::contains($phone->value, '877')
                    && ! Str::contains($phone->value, '866')
                    && ! Str::contains($phone->value, '855')
                    && ! Str::contains($phone->value, '844')
                    && ! Str::contains($phone->value, '833')
                ) {
                    if ($phoneCount == 0 && ! empty($phone->value)) {
                        $phoneValue = preg_replace('/\D+/', '', $phone->value);
                        if (in_array($phoneValue, $phoneExist)) {
                            continue;
                        }

                        $customerData['phones'][] = [
                            'number' => preg_replace('/\D+/', '', $phoneValue),
                            'phoneType' => 'Cellular',
                            'preferredTimeToContact' => 'Unspecified',
                        ];

                        $phoneExist[] = $phoneValue;
                        $phoneCount++;
                    }
                }
            }
        } else {
            unset($customerData['phones']);
        }

        $addressCollection = $people->address()->get();
        $firstAddress = $addressCollection->first();

        if (
            $addressCollection->count() &&
            ! empty(trim((string) $firstAddress->zip)) &&
            ! empty(trim((string) $firstAddress->city)) &&
            ! empty(trim((string) $firstAddress->state)) &&
            ! empty(trim((string) $firstAddress->address))
        ) {
            $customerData['address'] = [
                'addressLine1' => $firstAddress->address,
                'addressLine2' => $firstAddress->address_2 ?? '',
                'city' => $firstAddress->city,
                'state' => $firstAddress->state,
                'zip' => $firstAddress->zip,
                'country' => $firstAddress->country->code ?? 'US', // Adjusted to properly fetch country
            ];
        } else {
            unset($customerData['address']);
        }

        return $customerData;
    }

    /**
     * Create a new customer.
     */
    public static function create(AppInterface $app, Companies $company, array $data): self
    {
        $client = new Client($app, $company);
        $response = $client->post(
            '/sales/v1/elead/customers/',
            $data,
        );

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
            $data,
        );

        $this->assign($response);

        return $this;
    }

    public static function getById(AppInterface $app, Companies $company, string $id): self
    {
        $client = new Client($app, $company);
        $response = $client->get(
            '/sales/v1/elead/customers/' . $id,
        );

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
            throw new RuntimeException('This Customer doesn\'t have a reference in ELeads');
        }

        return self::getById(
            $people->app,
            $people->company,
            $customerId
        );
    }

    /**
     * Get owned vehicles.
     */
    public function getOwnedVehicles(): array
    {
        $client = new Client($this->app, $this->company);
        $response = $client->get(
            '/sales/v1/elead/customers/' . $this->id . '/ownedVehicles',
        );

        return $response;
    }

    /**
     * Get owned vehicles.
     */
    public function search(array $criteria, int $page = 1): array
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
            '/sales/v1/elead/customers/search?pageSize=25&page=' . $page,
            $searchCriteria
        );

        return $response;
    }
}
