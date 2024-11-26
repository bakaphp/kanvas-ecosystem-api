<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Square\Actions;

use Kanvas\Connectors\Square\Client;
use Kanvas\Connectors\Square\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Square\Models\Address;
use Square\Models\CreateCustomerRequest;
use Square\Models\Customer;

class SavePeopleToSquareCustomerAction
{
    public function __construct(
        protected People $people,
    ) {
    }

    public function execute(): Customer
    {
        $client = (new Client($this->people->app))->getClient();

        if ($customerId = $this->people->get(CustomFieldEnum::SQUARE_CUSTOMER_ID->value)) {
            $customersApi = $client->getCustomersApi();
            $response = $customersApi->retrieveCustomer($customerId);
        }

        // Dynamically populate address fields from people reference
        $address = null;
        if ($this->people->address()->count()) {
            $address = new Address();
            $address->setAddressLine1($this->people->address()->first()->address);
            $address->setLocality($this->people->address()->first()->city);
            $address->setAdministrativeDistrictLevel1($this->people->address()->first()->state);
            $address->setPostalCode($this->people->address()->first()->zip);
            $address->setCountry($this->people->address()->first()->country);
        }

        // Create Customer Request object
        $createCustomerRequest = new CreateCustomerRequest();
        $createCustomerRequest->setGivenName($this->people->firstname);
        $createCustomerRequest->setFamilyName($this->people->lastname);
        $createCustomerRequest->setEmailAddress($this->people->getEmails()?->first()?->email);
        $createCustomerRequest->setPhoneNumber($this->people->getPhones()?->first()?->phone);

        // Set address if it exists
        if ($address) {
            $createCustomerRequest->setAddress($address);
        }

        // Call the Create Customer API
        $customersApi = $client->getCustomersApi();
        $response = $customersApi->createCustomer($createCustomerRequest);

        if ($response->isSuccess()) {
            $customer = $response->getResult()->getCustomer();
            $this->people->set(CustomFieldEnum::SQUARE_CUSTOMER_ID->value, $customer->getId());
        }

        return $customer;
    }
}
