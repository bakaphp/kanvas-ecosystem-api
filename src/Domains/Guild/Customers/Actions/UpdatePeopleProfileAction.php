<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Actions;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Customers\Models\Contact;
use Kanvas\Guild\Customers\Models\People;

class UpdatePeopleProfileAction
{
    public function __construct(
        private readonly People $people,
        private readonly AppInterface $app,
        private readonly ?string $firstname = null,
        private readonly ?string $lastname = null,
        private readonly ?string $middlename = null,
        private readonly ?string $dob = null,
        private readonly ?string $phone = null,
        private readonly ?string $address = null,
        private readonly ?string $city = null,
        private readonly ?string $state = null,
        private readonly ?string $zip = null,
    ) {
    }

    public function execute(): People
    {
        return DB::connection('crm')->transaction(function (): People {
            if ($this->firstname !== null || $this->lastname !== null || $this->middlename !== null) {
                $firstname = $this->firstname ?? (string) $this->people->firstname;
                $middlename = $this->middlename ?? (string) $this->people->middlename;
                $lastname = $this->lastname ?? (string) $this->people->lastname;

                $this->people->firstname = $firstname;
                $this->people->middlename = $middlename;
                $this->people->lastname = $lastname;
                $this->people->name = trim(preg_replace('/\s+/', ' ', "{$firstname} {$middlename} {$lastname}") ?? '');
            }

            if ($this->dob !== null) {
                $this->people->dob = $this->dob;
            }

            $this->people->saveOrFail();

            if ($this->phone !== null && $this->phone !== '') {
                $this->upsertCellphone();
            }

            if ($this->address !== null && $this->address !== '') {
                $this->upsertDefaultAddress();
            }

            return $this->people;
        });
    }

    private function upsertCellphone(): void
    {
        $contact = $this->people->contacts()
            ->where('contacts_types_id', ContactTypeEnum::CELLPHONE->value)
            ->where('is_deleted', 0)
            ->first();

        if ($contact instanceof Contact) {
            $contact->value = (string) $this->phone;
            $contact->saveOrFail();

            return;
        }

        $this->people->contacts()->save(
            new Contact([
                'contacts_types_id' => ContactTypeEnum::CELLPHONE->value,
                'value' => $this->phone,
                'weight' => 50,
            ])
        );
    }

    private function upsertDefaultAddress(): void
    {
        /** @var Address|null $existing */
        $existing = $this->people->address()
            ->where('is_default', 1)
            ->where('is_deleted', 0)
            ->first();

        $addressModel = $existing ?? new Address([
            'is_default' => 1,
            'address_type_id' => AddressType::getByName(AddressTypeEnum::HOME->value, $this->app)->getId(),
            'countries_id' => 0,
        ]);

        $addressModel->address = (string) $this->address;
        if ($this->city !== null) {
            $addressModel->city = $this->city;
        }
        if ($this->state !== null) {
            $addressModel->state = $this->state;
        }
        if ($this->zip !== null) {
            $addressModel->zip = $this->zip;
        }

        if ($existing === null) {
            $this->people->address()->save($addressModel);

            return;
        }

        $addressModel->saveOrFail();
    }
}
