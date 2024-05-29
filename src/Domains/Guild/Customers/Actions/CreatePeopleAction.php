<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDataInput;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;

class CreatePeopleAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly PeopleDataInput $peopleData
    ) {
    }

    /**
     * execute.
     */
    public function execute(): People
    {
        $company = $this->peopleData->branch->company()->firstOrFail();

        $attributes = [
            'apps_id' => $this->peopleData->app->getId(),
            'users_id' => $this->peopleData->user->getId(),
            'firstname' => $this->peopleData->firstname,
            'middlename' => $this->peopleData->middlename,
            'lastname' => $this->peopleData->lastname,
            'name' => $this->peopleData->firstname . ' ' . $this->peopleData->lastname, // @todo remove this
            'dob' => $this->peopleData->dob,
            'google_contact_id' => $this->peopleData->google_contact_id,
            'facebook_contact_id' => $this->peopleData->facebook_contact_id,
            'apple_contact_id' => $this->peopleData->apple_contact_id,
        ];

        //@todo how to avoid duplicated? should it be use or frontend?
        if ($this->peopleData->id) {
            $people = PeoplesRepository::getById($this->peopleData->id, $company);
            $people->update($attributes);
        } else {
            $attributes['companies_id'] = $company->getId();
            $people = People::create($attributes);
        }

        $people->setCustomFields($this->peopleData->custom_fields);
        $people->saveCustomFields();

        if ($this->peopleData->contacts->count()) {
            $contacts = [];
            foreach ($this->peopleData->contacts as $contact) {
                $contacts[] = new Contact([
                    'contacts_types_id' => $contact->contacts_types_id,
                    'value' => $contact->value,
                    'weight' => $contact->weight,
                ]);
            }

            $people->contacts()->saveMany($contacts);
        }

        if ($this->peopleData->address->count()) {
            $addresses = [];
            foreach ($this->peopleData->address as $address) {
                $addresses[] = new Address([
                    'address' => $address->address,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'county' => $address->county,
                    'state' => $address->state,
                    'zip' => $address->zipcode,
                    //'country' => $address->country,
                    'is_default' => $address->is_default,
                    'city_id' => $address->city_id ?? 0,
                    'state_id' => $address->state_id ?? 0,
                    'countries_id' => $address->country_id ?? 0,
                ]);
            }

            $people->address()->saveMany($addresses);
        }

        return $people;
    }
}
