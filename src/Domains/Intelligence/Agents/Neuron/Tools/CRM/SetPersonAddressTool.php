<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Guild\Customers\DataTransferObject\Address as AddressData;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\Address;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesAddressesForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * Without address_id this adds (People::addAddress dedups an identical address); with it, it patches
 * that row in place — otherwise "fix the zip" would leave the wrong address behind as a second row.
 */
#[AgentTool(name: 'Set Person Address', category: 'crm')]
class SetPersonAddressTool extends Tool
{
    use HandlesAddressesForTool;
    use ReportsToolOutcome;
    use ResolvesPersonForTool;
    use TrackByInputs;

    protected string $name = 'set_person_address';

    protected ?string $description = 'Add or correct a postal address on a person. To fix an existing address pass its address_id '
        . '(from get_person) and only the fields that change; without address_id a new address is added. '
        . 'Set is_default=true to make it the person\'s current address.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'The id of the person.', required: true),
            new ToolProperty(
                name: 'address_id',
                type: PropertyType::INTEGER,
                description: 'The id of an existing address of this person to update. Omit to add a new one.',
                required: false,
            ),
            new ToolProperty(name: 'address', type: PropertyType::STRING, description: 'Street address. Required when adding.', required: false),
            new ToolProperty(name: 'address_2', type: PropertyType::STRING, description: 'Apartment, suite, sector.', required: false),
            new ToolProperty(name: 'city', type: PropertyType::STRING, description: 'City.', required: false),
            new ToolProperty(name: 'state', type: PropertyType::STRING, description: 'State / province.', required: false),
            new ToolProperty(name: 'zip', type: PropertyType::STRING, description: 'Postal code.', required: false),
            new ToolProperty(name: 'country', type: PropertyType::STRING, description: 'Country name, e.g. "Dominican Republic".', required: false),
            new ToolProperty(
                name: 'type',
                type: PropertyType::STRING,
                description: 'Address type. Defaults to Home.',
                required: false,
                enum: array_column(AddressTypeEnum::cases(), 'value'),
            ),
            new ToolProperty(name: 'is_default', type: PropertyType::BOOLEAN, description: 'Make this the current address.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $person_id,
        ?int $address_id = null,
        ?string $address = null,
        ?string $address_2 = null,
        ?string $city = null,
        ?string $state = null,
        ?string $zip = null,
        ?string $country = null,
        ?string $type = null,
        ?bool $is_default = null,
    ): array {
        $result = $this->resolvePersonOrError($person_id);
        if (is_array($result)) {
            return $result;
        }
        $person = $result;

        $addressType = null;
        if (Str::trimToNull($type) !== null) {
            $typeEnum = AddressTypeEnum::tryFrom(trim($type));
            if ($typeEnum === null) {
                return $this->invalidArgs(sprintf('Unknown address type "%s".', $type));
            }
            $addressType = AddressType::getByName($typeEnum->value, $this->app);
        }

        $countryId = $this->resolveCountryIdOrError($country);
        if (is_array($countryId)) {
            return $countryId;
        }

        $fields = array_filter(
            [
                'address' => Str::trimToNull($address),
                'address_2' => Str::trimToNull($address_2),
                'city' => Str::trimToNull($city),
                'state' => Str::trimToNull($state),
                'zip' => Str::trimToNull($zip),
                'countries_id' => $countryId,
                'address_type_id' => $addressType?->getId(),
            ],
            fn ($value) => $value !== null
        );

        if ($address_id !== null) {
            /** @var Address|null $stored */
            $stored = $person->address()->where('id', $address_id)->first();
            if ($stored === null) {
                return $this->notFound(['error' => sprintf('Person #%d has no address #%d.', $person->getId(), $address_id)]);
            }

            $stored->forceFill($fields)->saveOrFail();
        } else {
            if (! isset($fields['address'])) {
                return $this->invalidArgs('address is required when adding a new address.');
            }

            $stored = $person->addAddress(new AddressData(
                address: $fields['address'],
                address_2: $fields['address_2'] ?? null,
                city: $fields['city'] ?? null,
                state: $fields['state'] ?? null,
                zip: $fields['zip'] ?? null,
                country: $countryId !== null ? trim((string) $country) : null,
                address_type_id: $fields['address_type_id'] ?? null,
            ));
        }

        if ($is_default === true) {
            $person->makeDefaultAddress($stored);
        }

        return $this->ok([
            'person_id' => $person->getId(),
            'address' => $this->presentAddress($stored->refresh()),
            'message' => $address_id !== null ? 'Address updated.' : 'Address saved.',
        ]);
    }
}
