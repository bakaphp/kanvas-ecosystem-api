<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\Entities\Customer;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\SyncPeopleByThirdPartyCustomFieldAction;
use Kanvas\Guild\Customers\DataTransferObject\People as DataTransferObjectPeople;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Locations\Models\Countries;

class PullPeopleAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user
    ) {
    }

    public function execute(array $request): array
    {
        $phones = $request['phones'] ?? [];
        $emails = $request['emails'] ?? [];
        $email = $emails[0] ?? null;
        $dob = $request['birthday'] ?? null;
        $firstname = $request['firstname'] ?? null;
        $lastname = $request['lastname'] ?? null;
        $personId = $request['personId'];
        $phone = $phones[0] ?? null;

        $people = People::getByCustomField(
            CustomFieldEnum::PERSON_ID->value,
            $personId,
            $this->company
        );

        if ($people !== null) {
            return [$people];
        }

        $eLeadCustomer = new Customer();
        $eLeadCustomer->company = $this->company;
        $eLeadCustomer->app = $this->app;

        //if the email is not complete , add the .com for the search
        if (is_string($email) && strpos($email, 'gmail.') !== false && strpos($email, 'gmail.com') === false) {
            $email .= 'com';
        }

        $params = [
            'phoneNumber' => $phone,
            'emailAddress' => $email,
            'firstName' => $firstname,
            'lastName' => $lastname,
        ];

        //if email doesn't have a @ , remove it
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            unset($params['emailAddress']);
        }

        $results = [];
        $customers = $eLeadCustomer->search($params);
        $country = Countries::getByCode('US');

        if ($customers && isset($customers['items'])) {
            foreach ($customers['items'] as $customer) {
                if ($customer['rank'] < 0.4) {
                    continue;
                }

                $people = new SyncPeopleByThirdPartyCustomFieldAction(
                    DataTransferObjectPeople::from([
                        'app' => $this->app,
                        'company' => $this->company,
                        'user' => $this->user,
                        'firstname' => $customer['firstName'],
                        'lastname' => $customer['lastName'],
                        'dob' => $customer['birthday'] ?? null,
                        'contacts' => array_merge(
                            array_map(
                                fn ($email) => [
                                    'value' => $email['address'],
                                    'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                                    'weight' => 0,
                                ],
                                $customer['emails'] ?? []
                            ),
                            array_map(
                                fn ($phone) => [
                                    'value' => $phone['number'],
                                    'contacts_types_id' => ContactTypeEnum::PHONE->value,
                                    'weight' => 0,
                                ],
                                $customer['phones'] ?? []
                            )
                        ),
                        'address' => array_map(
                            fn ($address) => [
                                    'address' => $address['addressLine1'] ?? '',
                                    'city' => $address['city'] ?? '',
                                    'state' => $address['state'] ?? '',
                                    'country' => $country->name,
                                    'country_id' => $country->id,
                                    'zip' => $address['zip'] ?? '',
                                ],
                            $customer['address'] ?? []
                        )
                        ,
                        'branch' => $this->company->defaultBranch,
                        'custom_fields' => [
                            CustomFieldEnum::CUSTOMER_ID->value => $customer['id'],
                        ],
                    ])
                )->execute();

                $results[] = $people;
            }
        }

        return $results;
    }
}
