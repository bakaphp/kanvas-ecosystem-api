<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Kanvas\Companies\Models\Companies;

class IndexCustomerBySearch
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company
    ) {
    }

    /**
     * Search for a specific string and index all found leads.
     */
    public function execute(array $search): array
    {
        /*         $eLeadCustomer = new ServicesCustomer();
                $eLeadCustomer->company = $this->company;
                $eLeadCustomer->app = $this->app;
                $customers = $eLeadCustomer->search([
                    'phoneNumber' => $search['phone'] ?? '',
                    'emailAddress' => $search['email'] ?? '',
                    'firstName' => $search['firstName'] ?? '',
                    'lastName' => $search['lastName'] ?? '',
                ]);

                $results = [];
                if ($customers && isset($customers['items']) && $customers['totalItems'] > 0) {
                    foreach ($customers['items'] as $customer) {
                        try {
                            $peopleData = PeoplesData::createFromArray([
                                'firstname' => $customer['firstName'],
                                'lastname' => $customer['lastName'],
                                'middlename' => $customer['middleName'] ?? '',
                                'email' => isset($customer['emails']) && count($customer['emails']) ? $customer['emails'][0]['address'] : '',
                                'phone' => isset($customer['phones']) && count($customer['phones']) ? $customer['phones'][0]['number'] : '',
                                'dob' => null,
                                'address' => $customer['address']['addressLine1'] ?? '',
                                'address_2' => $customer['address']['addressLine2'] ?? '',
                                'city' => $customer['address']['city'] ?? '',
                                'state' => $customer['address']['state'] ?? '',
                                'zip' => $customer['address']['zip'] ?? '',
                            ]);
                            $peopleData->emails = $customer['emails'] ?? [];
                            $peopleData->phones = $customer['phones'] ?? [];
                            $peopleData->addresses = $customer['address'] ?? [];
                            $peopleData->thirdPartyCustomFieldKey = Flag::CUSTOMER_ID;
                            $peopleData->thirdPartyCustomFieldValue = $customer['id'];

                            $peopleAction = new SyncPeopleByCustomFieldAction($this->company);
                            $peopleAction->execute($peopleData);
                        } catch (Throwable $e) {
                        }
                    }
                } */

        //return $customers;
        return [];
    }
}
