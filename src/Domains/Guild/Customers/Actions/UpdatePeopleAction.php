<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDataInput;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;

class UpdatePeopleAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected People $people,
        protected readonly PeopleDataInput $peopleData
    ) {
    }

    /**
     * execute.
     */
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
        ];

        //@todo how to avoid duplicated? should it be use or frontend?
        $this->people->update($attributes);

        $this->people->setCustomFields($this->peopleData->custom_fields);
        $this->people->saveCustomFields();

        if (count($this->peopleData->tags)) {
            $this->people->syncTags(array_column($this->peopleData->tags, 'name'));
        }

        if ($this->peopleData->contacts->count()) {
            $contacts = [];
            $this->people->contacts()->delete();
            foreach ($this->peopleData->contacts as $contact) {
                $contacts[] = new Contact([
                    'contacts_types_id' => $contact->contacts_types_id,
                    'value' => $contact->value,
                    'weight' => $contact->weight,
                ]);
            }

            $this->people->contacts()->saveMany($contacts);
        }

        if ($this->peopleData->address->count()) {
            $addresses = [];
            $this->people->address()->delete();
            foreach ($this->peopleData->address as $address) {
                $addresses[] = new Address([
                    'address' => $address->address,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'zip' => $address->zipcode,
                    //'country' => $address->country,
                    'is_default' => $address->is_default,
                    'city_id' => $address->city_id ?? 0,
                    'state_id' => $address->state_id ?? 0,
                    'countries_id' => $address->country_id ?? 0,
                ]);
            }

            $this->people->address()->saveMany($addresses);
        }

        return $this->people;
    }
}
