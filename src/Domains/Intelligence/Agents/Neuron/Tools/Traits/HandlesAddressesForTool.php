<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Models\Address as PeopleAddress;
use Kanvas\Guild\Organizations\Models\Address as OrganizationAddress;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Locations\Models\Countries;
use Throwable;

trait HandlesAddressesForTool
{
    use ReportsToolOutcome;

    /**
     * @return int|array<string, mixed>|null the country id, null when none was given, or an error to return
     */
    protected function resolveCountryIdOrError(?string $country): int|array|null
    {
        $name = Str::trimToNull($country);
        if ($name === null) {
            return null;
        }

        try {
            return Countries::getByName($name)->getId();
        } catch (Throwable) {
            return $this->invalidArgs(sprintf('Unknown country "%s". Nothing was changed.', $name));
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentAddress(PeopleAddress|OrganizationAddress $address): array
    {
        return [
            'address_id' => $address->getId(),
            'type' => $address->type?->name,
            'address' => $address->address,
            'address_2' => $address->address_2,
            'city' => $address->city,
            'state' => $address->state,
            'zip' => $address->zip,
            'country' => $address->country?->name,
            'is_default' => (bool) $address->is_default,
        ];
    }
}
