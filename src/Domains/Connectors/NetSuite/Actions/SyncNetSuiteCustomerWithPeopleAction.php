<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Enums\CustomFieldEnum;
use Kanvas\Connectors\NetSuite\Services\NetSuiteCustomerService;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\Actions\UpdatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as DataTransferObjectPeople;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use NetSuite\Classes\CustomFieldList;
use Spatie\LaravelData\DataCollection;

class SyncNetSuiteCustomerWithPeopleAction
{
    protected NetSuiteCustomerService $service;

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company
    ) {
        $this->service = new NetSuiteCustomerService($app, $company);
    }

    public function execute(int|string $customerId): People
    {
        $linkPeople = People::getByCustomField(
            CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value,
            $customerId,
            $this->company
        );

        $customerInfo = $this->service->getCustomerById($customerId);

        if (empty($customerInfo->firstName) && ! empty($customerInfo->companyName)) {
            $customerInfo->firstName = $customerInfo->companyName;
        }

        $peopleData = new DataTransferObjectPeople(
            app: $this->app,
            branch: $this->company->defaultBranch,
            user: $this->company->user,
            firstname: $customerInfo->firstName,
            contacts: new DataCollection(Contact::class, array_filter([
                $customerInfo->email ? [
                    'value' => $customerInfo->email,
                    'contacts_types_id' => ContactTypeEnum::EMAIL->value,
                    'weight' => 0,
                ] : [],
                $customerInfo->phone ? [
                    'value' => $customerInfo->phone,
                    'contacts_types_id' => ContactTypeEnum::PHONE->value,
                    'weight' => 0,
                ] : [],
            ])),
            address: new DataCollection(Address::class, $customerInfo->addressbookList->addressbook[0]?->addressbookAddress ? [
                [
                    'address' => $customerInfo->addressbookList->addressbook[0]->addressbookAddress->addressee ?? '',
                    'city' => $customerInfo->addressbookList->addressbook[0]->addressbookAddress->city ?? '',
                    'state' => $customerInfo->addressbookList->addressbook[0]->addressbookAddress->state ?? '',
                    'zip_code' => $customerInfo->addressbookList->addressbook[0]->addressbookAddress->zip ?? '',
                    'country' => $customerInfo->addressbookList->addressbook[0]->addressbookAddress->country ?? '',
                    'weight' => 0,
                ],
            ] : []),
            lastname: $customerInfo->lastName,
            organization: $customerInfo->companyName,
            custom_fields: $this->convertCustomFields($customerInfo->customFieldList)
        );

        $createPeople = $linkPeople ? new UpdatePeopleAction($linkPeople, $peopleData) : new CreatePeopleAction($peopleData);
        $people = $createPeople->execute();

        if (! $linkPeople) {
            $people->set(CustomFieldEnum::NET_SUITE_CUSTOMER_ID->value, $customerId);
        }

        return $people;
    }

    protected function convertCustomFields(CustomFieldList $customFieldList): array
    {
        $customFields = [];
        foreach ($customFieldList->customField as $field) {
            $scriptId = $field->scriptId;

            // Check if the value is an object and convert it to an array; otherwise, use the value directly
            $value = is_object($field->value) ? json_decode(json_encode($field->value), true) : ($field->value ?? '');

            if ($value == '' || $value == null) {
                continue;
            }

            $customFields[$scriptId] = $value;
        }

        return $customFields;
    }
}
