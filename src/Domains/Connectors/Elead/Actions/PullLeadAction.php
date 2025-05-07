<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Elead\DataTransferObject\Lead as DataTransferObjectLead;
use Kanvas\Connectors\Elead\Entities\Customer;
use Kanvas\Connectors\Elead\Entities\Lead;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Actions\SyncLeadByThirdPartyCustomFieldAction;
use Kanvas\Locations\Models\Countries;
use Throwable;

class PullLeadAction
{
    public function __construct(
        protected AppInterface $app,
        protected Companies $company,
        protected UserInterface $user
    ) {
    }

    public function execute(array $request): array
    {
        $phone = $request['phone']['cell'] ?? $request['phone']['home'] ?? $request['phone']['work'] ?? null;
        //$emails = $request['emails'] ?? [];
        $email = $request['email'] ?? null;
        $dob = $request['birthday'] ?? null;
        $firstname = $request['firstname'] ?? null;
        $lastname = $request['lastname'] ?? null;
        $personId = $request['personId'] ?? $request['entity_id'] ?? null;

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

                try {
                    $eLead = Lead::getByCustomerId($this->app, $this->company, $customer['id']);
                    $eLead->customerId = $customer['id'];

                    $lead = new SyncLeadByThirdPartyCustomFieldAction(
                        DataTransferObjectLead::fromLeadEntity($eLead, $this->user)
                    )->execute();

                    $results[] = $lead;
                } catch (Throwable $th) {
                    //ignore the error
                    continue;
                }
            }
        }

        return $results;
    }
}
