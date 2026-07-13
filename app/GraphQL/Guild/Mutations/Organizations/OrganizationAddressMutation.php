<?php

declare(strict_types=1);

namespace App\GraphQL\Guild\Mutations\Organizations;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Organizations\Actions\AddAddressToOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Organizations\Models\Address;
use Kanvas\Guild\Organizations\Models\Organization;

class OrganizationAddressMutation
{
    public function add(mixed $root, array $request): Address
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        /** @var Organization $organization */
        $organization = Organization::getByIdFromCompanyApp(
            (int) $request['organization_id'],
            $company,
            $app,
        );

        return new AddAddressToOrganizationAction(
            AddressData::from($organization, $request['input'], $user),
        )->execute();
    }

    /**
     * Routes through the same action as `add` because an update can change the TYPE — retyping Shipping to
     * Billing must collapse onto the existing Billing row, not leave the organization with two.
     */
    public function update(mixed $root, array $request): Address
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $address = $this->addressFromRequest((int) $request['id'], $company, $app);

        // A PATCH: omitted fields keep their current value, so correcting the zip doesn't wipe the street.
        $input = (array) $request['input'] + [
            'type' => $address->addressTypeName() ?? AddressTypeEnum::BILLING->value,
            'address' => $address->address,
            'address_2' => $address->address_2,
            'city' => $address->city,
            'county' => $address->county,
            'state' => $address->state,
            'zip' => $address->zip,
            'country_id' => $address->countries_id,
            'state_id' => $address->state_id,
            'city_id' => $address->city_id,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'is_default' => $address->is_default,
        ];

        return new AddAddressToOrganizationAction(
            AddressData::from($address->organization, $input, $user),
        )->execute();
    }

    public function delete(mixed $root, array $request): bool
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        return (bool) $this->addressFromRequest((int) $request['id'], $company, $app)->delete();
    }

    /**
     * `organizations_address` carries no tenant columns, so the parent Organization is the boundary. The
     * scoped lookup here is what stops an id from another company resolving.
     */
    private function addressFromRequest(int $addressId, mixed $company, Apps $app): Address
    {
        $address = Address::query()
            ->where('id', $addressId)
            ->where('is_deleted', false)
            ->first();

        if ($address === null) {
            throw new ValidationException("Organization address {$addressId} not found.");
        }

        Organization::getByIdFromCompanyApp($address->organizations_id, $company, $app);

        return $address;
    }
}
