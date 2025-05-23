<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Contracts\CompanyInterface;
use Baka\Validations\Date;
use Kanvas\Companies\Enums\Defaults;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleDataInput;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Repositories\PeoplesRepository;
use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreatePeopleAction
{
    public bool $runWorkflow = true;

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
        $allowDuplicateContacts = (bool) ($company->get(Defaults::ALLOW_DUPLICATE_CONTACTS->getValue()) ?? false);

        if (! $allowDuplicateContacts) {
            $this->checkIfPeopleExist($company);
        }

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

        if (Date::isValid($this->peopleData->created_at, 'Y-m-d H:i:s')) {
            $attributes['created_at'] = date('Y-m-d H:i:s', strtotime($this->peopleData->created_at));
        }

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

        if (count($this->peopleData->tags)) {
            $people->syncTags($this->peopleData->tags);
        }

        if ($this->peopleData->contacts->count()) {
            $existingContacts = $people->contacts()->pluck('value')->toArray();
            $contactsToAdd = [];

            foreach ($this->peopleData->contacts as $contact) {
                if (empty($contact->value)) {
                    continue;
                }

                if (! in_array($contact->value, $existingContacts)) {
                    $contactsToAdd[] = new Contact([
                        'contacts_types_id' => $contact->contacts_types_id,
                        'value' => $contact->value,
                        'weight' => $contact->weight,
                    ]);
                }
            }

            if (! empty($contactsToAdd)) {
                $people->contacts()->saveMany($contactsToAdd);
            }
        }

        if ($this->peopleData->address->count()) {
            $existingAddresses = $people->address()
                ->select('address', 'address_2', 'city', 'county', 'state', 'zip', 'city_id', 'state_id', 'countries_id')
                ->get()
                ->toArray();

            $hasDefaultAddress = $people->address()->where('is_default', 1)->exists();

            $addressesToAdd = [];

            foreach ($this->peopleData->address as $address) {
                $newAddress = [
                    'address' => $address->address,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'county' => $address->county,
                    'state' => $address->state,
                    'zip' => $address->zip,
                    'city_id' => $address->city_id ?? 0,
                    'state_id' => $address->state_id ?? 0,
                    'countries_id' => $address->country_id ?? 0,
                    'address_type_id' => $address->address_type_id ?? AddressType::getByName(AddressTypeEnum::HOME->value, $this->peopleData->app)->getId(),
                    'duration' => $address->duration ?? 0.0,
                ];

                if (! in_array($newAddress, $existingAddresses)) {
                    $addressesToAdd[] = $addressesToAdd[] = new Address(array_merge($newAddress, [
                        'is_default' => $hasDefaultAddress ? 0 : ($address->is_default ? 1 : 0),
                    ]));
                }
            }
        }
        if ($this->peopleData->peopleEmploymentHistory) {
            foreach ($this->peopleData->peopleEmploymentHistory as $employmentHistory) {
                $people->employmentHistory()->updateOrCreate(
                    [
                        'organizations_id' => $employmentHistory['organizations_id'],
                        'apps_id' => $this->peopleData->app->getId(),
                        'position' => $employmentHistory['position'],
                    ],
                    [
                        'position' => $employmentHistory['position'],
                        'income' => $employmentHistory['income'],
                        'start_date' => $employmentHistory['start_date'],
                        'end_date' => $employmentHistory['end_date'],
                        'status' => $employmentHistory['status'],
                        'income_type' => $employmentHistory['income_type'] ?? null,
                    ]
                );
            }
        }

        if (! empty($this->peopleData->organization)) {
            $organization = (new CreateOrganizationAction(
                new Organization(
                    company: $this->peopleData->branch->company,
                    user: $this->peopleData->user,
                    app: $this->peopleData->app,
                    name: $this->peopleData->organization,
                )
            ))->execute();
            OrganizationPeople::addPeopleToOrganization($organization, $people);
        }

        if (! empty($addressesToAdd)) {
            $people->address()->saveMany($addressesToAdd);
        }

        if ($this->runWorkflow) {
            $people->fireWorkflow(
                WorkflowEnum::CREATED->value,
                true,
                [
                    'app' => $this->peopleData->app,
                ]
            );
        }

        $people->refresh();

        return $people;
    }

    protected function checkIfPeopleExist(CompanyInterface $company): void
    {
        if ($this->peopleData->contacts->count()) {
            foreach ($this->peopleData->contacts as $contact) {
                //only check for phone or email
                if (! in_array($contact->contacts_types_id, [ContactTypeEnum::EMAIL->value, ContactTypeEnum::PHONE->value, ContactTypeEnum::CELLPHONE->value])) {
                    continue;
                }
                $searchValue = $contact->value;

                $people = PeoplesRepository::getByValue($searchValue, $company, $this->peopleData->app);
                if ($people) {
                    $this->peopleData->id = $people->getId();

                    return ;
                }
            }
        }
    }
}
