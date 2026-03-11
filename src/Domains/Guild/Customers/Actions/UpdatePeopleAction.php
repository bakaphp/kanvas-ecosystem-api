<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDataInput;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Traits\ManagesPeopleContactsTrait;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdatePeopleAction
{
    use ManagesPeopleContactsTrait;

    public bool $runWorkflow = true;

    public function __construct(
        protected People $people,
        protected readonly PeopleDataInput $peopleData
    ) {
    }

    public function execute(): People
    {
        $attributes = [
            'firstname' => $this->peopleData->firstname,
            'middlename' => $this->peopleData->middlename,
            'lastname' => $this->peopleData->lastname,
            'name' => $this->peopleData->firstname . ' ' . $this->peopleData->lastname, // @todo remove this
            'dob' => $this->peopleData->dob,
            'google_contact_id' => $this->peopleData->google_contact_id,
            'facebook_contact_id' => $this->peopleData->facebook_contact_id,
            'apple_contact_id' => $this->peopleData->apple_contact_id,
            'license_number' => $this->peopleData->license_number,
        ];

        //@todo how to avoid duplicated? should it be use or frontend?
        $this->people->updateOrFail($attributes);

        $this->people->setCustomFields($this->peopleData->custom_fields);
        $this->people->saveCustomFields();

        $this->people->syncTags($this->peopleData->tags);

        $this->syncContactsForUpdate($this->people, $this->peopleData->contacts);

        if ($this->peopleData->address->count()) {
            $this->syncAddressesForUpdate();
        }

        if ($this->peopleData->organization) {
            $organization = new CreateOrganizationAction(
                new Organization(
                    company: $this->peopleData->branch->company,
                    user: $this->peopleData->user,
                    app: $this->peopleData->app,
                    name: $this->peopleData->organization,
                )
            )->execute();
            OrganizationPeople::addPeopleToOrganization($organization, $this->people);
        }

        if ($this->runWorkflow) {
            $this->people->fireWorkflow(
                WorkflowEnum::UPDATED->value,
                true,
                [
                    'app' => $this->people->app,
                ]
            );
        }

        //$this->people->clearLightHouseCacheJob();
        return $this->people;
    }

    protected function syncAddressesForUpdate(): void
    {
        if ($this->peopleData->flushPreviousAddress) {
            $this->people->address()->delete();
        }

        $deduplicatedAddresses = $this->peopleData->address
            ->toCollection()
            ->filter(fn ($address) => ! empty($address->address))
            ->unique(function ($address) {
                return $address->address . '_' .
                       ($address->address_2 ?? '') . '_' .
                       ($address->city ?? '') . '_' .
                       ($address->state ?? '') . '_' .
                       ($address->zip ?? '') . '_' .
                       ($address->country_id ?? 0);
            })
            ->values();

        $addresses = [];

        foreach ($deduplicatedAddresses as $address) {
            $hasId = isset($address->id) && $address->id > 0;
            $existingAddress = ! $hasId ? $this->people->address()->where('address', $address->address)
                ->where('city', $address->city)
                ->where('state', $address->state)
                ->where('zip', $address->zip)
                ->first()
                : $this->people->address()->where('id', $address->id)->first();

            if (! $existingAddress) {
                $addresses[] = new Address([
                    'address' => $address->address,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'zip' => $address->zip,
                    'is_default' => $address->is_default,
                    'city_id' => $address->city_id ?? 0,
                    'state_id' => $address->state_id ?? 0,
                    'countries_id' => $address->country_id ?? 0,
                    'address_type_id' => $address->address_type_id ?? AddressType::getByName(AddressTypeEnum::HOME->value, $this->people->app)->getId(),
                    'duration' => $address->duration ?? 0.0,
                ]);
            } else {
                $existingAddress->update([
                    'address' => $address->address,
                    'city' => $address->city,
                    'state' => $address->state,
                    'zip' => $address->zip,
                    'address_2' => $address->address_2,
                    'is_default' => $address->is_default,
                    'countries_id' => $address->country_id ?? $existingAddress->countries_id,
                    'address_type_id' => $address->address_type_id ?? AddressType::getByName(AddressTypeEnum::HOME->value, $this->people->app)->getId(),
                ]);
            }
        }

        if (count($addresses) > 0) {
            $this->people->address()->saveMany($addresses);
        }
    }
}
