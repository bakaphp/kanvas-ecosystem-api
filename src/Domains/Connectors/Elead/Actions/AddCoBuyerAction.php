<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Connectors\Elead\Services\Customer;
use Kanvas\Guild\Leads\Models\Lead;

class AddCoBuyerAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(array $message): ?Customer
    {
        $syncLead = new SyncLeadAction($this->lead->company);
        $eLead = $syncLead->execute($this->lead);

        $formData = $message['data']['form'];

        /* $people = Peoples::findByEmailOrCreate(
            $formData['personal']['email'],
            $formData['personal']['first_name'] . ' ' . $formData['personal']['last_name'],
            $lead->companies
        );

        if (isset($formData['personal']['mobile_number']) && !empty($formData['personal']['mobile_number'])) {
            $people->savePhone($formData['personal']['mobile_number']);
        }

        $address = new Address();
        $address->peoples_id = $people->getId();
        $address->address = $formData['housing']['address'] ?? '';
        $address->address_2 = $formData['housing']['address_line2'] ?? '';
        $address->city = $formData['housing']['city']['name'] ?? '';
        $address->zip = $formData['housing']['city']['PostalCode'] ?? VinSolutions::DEFAULT_STATE;
        $address->saveOrFail();

        if ($people->getId() === $lead->people->getId()) {
            return null;
        }

        $lead->addParticipant($people);

        $syncPeopleAction = new SyncPeopleAction($this->company);
        $eLeadCustomer = $syncPeopleAction->execute($people); */

        //$eLead->addComment($formData['personal']['first_name'] . ' ' . $formData['personal']['last_name'] . ' added as Co-Signer');

        /*         if ($eLeadCustomer) {
                    $eLead->addComment($eLeadCustomer->firstName . ' ' . $eLeadCustomer->lastName . ' added as Co-Signer');

                    $lead->addCoBuyer($people);
                } */

        //return $eLeadCustomer;

        return null;
    }
}
