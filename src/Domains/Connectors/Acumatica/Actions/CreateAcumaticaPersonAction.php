<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportParty;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressDto;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDto;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Locations\Models\Countries;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

class CreateAcumaticaPersonAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
    ) {
    }

    /**
     * @param array<array-key, mixed> $row joined BAccount + Contact + Address row
     */
    public function execute(array $row, bool $isVendor = false): ?People
    {
        $party = AcumaticaImportParty::fromRow($row, $isVendor);

        if ($party->sourceId === '') {
            return null;
        }

        $idField = $isVendor ? CustomFieldEnum::VENDOR_ID : CustomFieldEnum::CUSTOMER_ID;

        /** @var People|null $existing */
        $existing = People::getByCustomField($idField->value, $party->sourceId, $this->company);

        return new CreatePeopleAction(
            PeopleDto::from([
                'app' => $this->app,
                'branch' => $this->user->getCurrentBranch(),
                'user' => $this->user,
                'firstname' => $party->firstname,
                'lastname' => $party->lastname,
                'contacts' => Contact::collect($this->contacts($party), DataCollection::class),
                'address' => AddressDto::collect($this->address($party), DataCollection::class),
                'custom_fields' => $party->customFields,
                'organization' => $party->organization,
                'id' => $existing?->getId() ?? 0,
                'runWorkflow' => false,
            ])
        )->execute();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function contacts(AcumaticaImportParty $party): array
    {
        $contacts = [];

        if ($party->email !== null) {
            $contacts[] = ['value' => $party->email, 'contacts_types_id' => ContactTypeEnum::EMAIL->value];
        }

        if ($party->phone !== null) {
            $contacts[] = ['value' => $party->phone, 'contacts_types_id' => ContactTypeEnum::PHONE->value];
        }

        return $contacts;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function address(AcumaticaImportParty $party): array
    {
        $a = $party->address;

        if ($a === null) {
            return [];
        }

        $countryId = $a->country !== null
            ? Countries::where('code', $a->country)->first()?->id
            : null;

        return [[
            'address' => $a->address,
            'address_2' => $a->address_2,
            'city' => $a->city,
            'state' => $a->state,
            'zip' => $a->zip,
            'countries_id' => $countryId,
            'latitude' => $a->latitude,
            'longitude' => $a->longitude,
        ]];
    }
}
