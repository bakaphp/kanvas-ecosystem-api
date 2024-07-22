<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Shopify\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Shopify\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as DataTransferObjectPeople;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Inventory\Regions\Models\Regions;
use Spatie\LaravelData\DataCollection;

class SyncShopifyCustomerAction
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region,
        protected array $customerData
    ) {
    }

    public function execute(): People
    {
        $customer = People::getByCustomField(
            CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value,
            $this->customerData['id'],
            $this->company
        );

        $contact = [];
        if (! empty($this->customerData['email'])) {
            $contact = [
                [
                    'value' => $this->customerData['email'],
                    'contacts_types_id' => 1,
                    'weight' => 0,
                ],
            ];
        }

        if (! empty($this->customerData['phone'])) {
            $contact[] = [
                'value' => $this->customerData['phone'],
                'contacts_types_id' => 2,
                'weight' => 0,
            ];
        }

        $address = [];
        if (! empty($this->customerData['default_address']) && ! empty($this->customerData['default_address']['address1'])) {
            $address = [
                [
                    'address' => $this->customerData['default_address']['address1'],
                    'address_2' => $this->customerData['default_address']['address2'],
                    'city' => $this->customerData['default_address']['city'],
                    'county' => $this->customerData['default_address']['province'],
                    'state' => $this->customerData['default_address']['province'],
                    'country' => $this->customerData['default_address']['country'],
                    'zipcode' => $this->customerData['default_address']['zip'],
                    'is_default' => true,
                ],
            ];
        }

        $peopleData = new DataTransferObjectPeople(
            app: $this->app,
            branch: $this->company->defaultBranch,
            user: $this->company->user,
            firstname: $this->customerData['first_name'],
            contacts: Contact::collect($contact, DataCollection::class),
            address: Address::collect($address, DataCollection::class),
            lastname: $this->customerData['last_name'],
            custom_fields: [
                CustomFieldEnum::SHOPIFY_CUSTOMER_ID->value => $this->customerData['id'],
            ]
        );

        if (! $customer) {
            $createPeople = new CreatePeopleAction($peopleData);
            $customer = $createPeople->execute();
        } else {
            $updatePeople = new UpdatePeopleAction($customer, $peopleData);
            $customer = $updatePeople->execute();
        }

        return $customer;
    }
}
