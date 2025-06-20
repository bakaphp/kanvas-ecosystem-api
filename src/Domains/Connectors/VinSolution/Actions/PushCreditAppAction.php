<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Kanvas\Connectors\SalesAssist\Enums\PeopleCustomFieldEnum;
use Kanvas\Connectors\VinSolution\ClientCredential;
use Kanvas\Connectors\VinSolution\DataTransferObject\CreditApp;
use Kanvas\Connectors\VinSolution\Dealers\Dealer;
use Kanvas\Connectors\VinSolution\Enums\AddressEnum;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Exceptions\VinSolutionException;
use Kanvas\Connectors\VinSolution\Leads\Contact;
use Kanvas\Connectors\VinSolution\Services\ContactService;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Social\Messages\Models\Message;

class PushCreditAppAction
{
    public function __construct(
        protected People $people,
        protected Message $message,
    ) {
    }

    public function execute(): Contact
    {
        $vinCompany = Dealer::getById($this->people->company->get(ConfigurationEnum::COMPANY->value), $this->people->app);

        $vinUserId = $this->people->user->get(ConfigurationEnum::getUserKey($this->people->company, $this->people->user));

        if (! $vinUserId) {
            throw new VinSolutionException(
                'User not found in VinSolution',
            );
        }

        $vinCredential = ClientCredential::get(
            $this->people->company,
            $this->people->user,
            $this->people->app
        );

        $user = Dealer::getUser(
            $vinCompany,
            $vinUserId,
            $this->app,
        );

        $vinContact = new ContactService(
            vinCredential: $vinCredential,
        );

        $contact = $vinContact->getContactByPeople($this->people);
        $creditApp = CreditApp::fromMessage($this->message);

        $contact->addresses = $creditApp->address;
        $contact->personalInformation = $creditApp->personalInformation;
        $contact->licenseData = $creditApp->licenseData;

        $this->people->addDefaultAddress(Address::from([
            'address' => $creditApp->address[0]['StreetAddress'],
            'address_2' => $creditApp->address[0]['StreetAddress2'],
            'city' => $creditApp->address[0]['City'],
            'state' => $creditApp->address[0]['State'],
            'zip' => $creditApp->address[0]['PostalCode'],
            'duration' => $creditApp->address[0]['Duration'],
        ]));

        //add other address
        if (isset($creditApp->address[1]) && ! empty($creditApp->address[1]['StreetAddress'])) {
            $this->people->addAddress(Address::from([
                'address' => $creditApp->address[1]['StreetAddress'],
                'address_2' => $creditApp->address[1]['StreetAddress2'],
                'city' => $creditApp->address[1]['City'],
                'state' => $creditApp->address[1]['State'],
                'zip' => $creditApp->address[1]['PostalCode'],
                'duration' => $creditApp->address[1]['Duration'],
                'address_type_id' => AddressType::getByName(AddressEnum::PREVIOUS_HOME->value)->getId(),
            ]));
        }

        $this->people->set(PeopleCustomFieldEnum::CREDIT_APP->value, $creditApp);

        return $contact->update($vinCompany, $user);
    }
}
