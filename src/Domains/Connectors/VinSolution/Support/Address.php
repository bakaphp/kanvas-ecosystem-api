<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Support;

use Kanvas\Connectors\VinSolution\Enums\AddressEnum;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Guild\Customers\Models\Address as ModelsAddress;
use Kanvas\Locations\Models\States;

class Address
{
    protected int $index = 0;
    protected ModelsAddress $address;

    /**
     * Constructor.
     *
     * @param int $index array Index
     */
    public function __construct(int $index, ModelsAddress $address)
    {
        $this->index = $index;
        $this->address = $address;
    }

    /**
     * Format PeopleAddress to vin solution format.
     *
     * @return array<string, mixed>
     */
    public function transform(): array
    {
        /*         $result['address'][1]['AddressId'] = 1;
                $result['address'][1]['AddressType'] = 'Previous'; */

        $type = $this->address->type && $this->address->type->name == AddressEnum::PREVIOUS_HOME->value ? 'Previous' : 'Primary';

        return [
            'AddressId' => $this->index,
            'AddressType' => $type ,
            'StreetAddress' => $this->getValue($this->address->address),
            'StreetAddress2' => null,
            'City' => $this->getCity(),
            'PostalCode' => $this->getValue($this->address->zip),
            'County' => $this->getValue($this->address->county),
            'State' => $this->getState(),
            'Duration' => null,
        ];
    }

    /**
     * Given the string return the current address value or null.
     */
    public function getValue(?string $value): ?string
    {
        return ! empty(trim((string) $value)) ? $value : null;
    }

    /**
     * Get the city.
     */
    public function getCity(): ?string
    {
        if ($this->address->getCity() === null) {
            return ! empty(trim((string) $this->address->city)) ? $this->address->city : null;
        }

        return $this->address->getCity()->name;
    }

    /**
     * Get the State.
     */
    public function getState(): ?string
    {
        $defaultState = $this->address->people && $this->address->people->companies ? $this->address->people->company->get(ConfigurationEnum::DEFAULT_STATE_KEY->value) : CustomFieldEnum::DEFAULT_STATE->value;

        if ($this->address->getState() === null) {
            if (! empty($this->address->state) && strlen($this->address->state) > 3) {
                $state = States::where('name', $this->address->state)->first();

                if ($state) {
                    return $state->code;
                }
            } elseif (! empty($this->address->state) && strlen($this->address->state) < 3) {
                return strtoupper($this->address->state);
            }

            return $defaultState;
            //return ! empty($this->address->state) && strlen($this->address->state) < 3 ? strtoupper($this->address->state) : $defaultState;
        }

        return $this->address->getState()->code;
    }
}
