<?php

namespace Kanvas\Companies\Actions;

use Baka\Contracts\AppInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesAddress;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;

class AddAddressToCompanyAction
{
    public function __construct(
        protected Companies $company,
        protected UserInterface $user,
        protected AppInterface $app
    ) {
    }

    public function execute(Address $address, bool $isDefault = false): CompaniesAddress
    {
        $country = Countries::getByCode($address->country);
        $city = Cities::where('name', $address->city)->first();
        $state = States::where('name', $address->state)->first();

        $address = CompaniesAddress::firstOrCreate(
            [
                'address' => $address->address,
                'companies_id' => $this->company->getId(),
                'countries_id' => $country->getId(),
            ],
            [
                'fullname' => $this->user->firstname . ' ' . $this->user->lastname,
                'phone' => $this->user->cell_phone_number,
                'address_2' => $address->address_2 ?? '',
                'city' => $city->name ?? $address->city,
                'county' => $address->county ?? '',
                'state' => $state->name ?? $address->state,
                'zip' => $address->zip ?? '',
                'city_id' => $city?->getId() ?? 0,
                'state_id' => $state?->getId() ?? 0,
                'is_default' => $isDefault,
            ]
        );

        return $address;
    }


    public function fromNetSuite(array $address): CompaniesAddress
    {
        return $this->execute(new Address(
            address: $address['addrtext_initialvalue'],
            city: $address['city_initialvalue'],
            state: $address['displaystate_initialvalue'],
            zip: $address['zip_initialvalue'],
            country: $address['country_initialvalue'],
            county: $address['county_initialvalue'] ?? '',
            address_2: $address['addrtext2_initialvalue'] ?? '',
        ), isDefault: true);
    }
}
