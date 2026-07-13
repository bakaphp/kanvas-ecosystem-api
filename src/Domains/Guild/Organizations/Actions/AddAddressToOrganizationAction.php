<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Organizations\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Organizations\Models\Address;

/**
 * One address per type: re-adding Billing updates the existing row rather than stacking a second, so
 * "the billing address" can't turn ambiguous the moment someone corrects a typo.
 */
class AddAddressToOrganizationAction
{
    public function __construct(
        public readonly AddressData $data,
    ) {
    }

    public function execute(): Address
    {
        return DB::connection('crm')->transaction(function (): Address {
            $organization = $this->data->organization;
            $addressType = AddressType::getByName($this->data->type->value, $organization->app);

            $address = Address::query()
                ->where('organizations_id', $organization->getId())
                ->where('address_type_id', $addressType->getId())
                ->where('is_deleted', false)
                ->first() ?? new Address();

            $address->organizations_id = $organization->getId();
            $address->address_type_id = $addressType->getId();
            $address->address = $this->data->address;
            $address->address_2 = $this->data->address_2;
            $address->city = $this->data->city;
            $address->county = $this->data->county;
            $address->state = $this->data->state;
            $address->zip = $this->data->zip;
            $address->countries_id = $this->data->countries_id;
            $address->city_id = $this->data->city_id;
            $address->state_id = $this->data->state_id;
            $address->latitude = $this->data->latitude;
            $address->longitude = $this->data->longitude;
            $address->is_default = $this->data->is_default;
            $address->save();

            if ($this->data->is_default) {
                Address::query()
                    ->where('organizations_id', $organization->getId())
                    ->where('id', '!=', $address->getId())
                    ->update(['is_default' => false]);
            }

            return $address->refresh();
        });
    }
}
