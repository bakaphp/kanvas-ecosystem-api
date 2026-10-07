<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Enums\AddressTypeEnum;
use Kanvas\Guild\Customers\Models\AddressType;
use Kanvas\Guild\Organizations\Actions\AddAddressToOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Address as AddressData;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesAddressesForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

/**
 * AddAddressToOrganizationAction keeps one address per type and overwrites every column, so a
 * partial correction is overlaid on the stored row here first — otherwise fixing a zip blanks the street.
 */
#[AgentTool(name: 'Set Organization Address', category: 'crm')]
class SetOrganizationAddressTool extends Tool
{
    use HandlesAddressesForTool;
    use ReportsToolOutcome;
    use ResolvesOrganizationForTool;
    use TrackByInputs;

    protected string $name = 'set_organization_address';

    protected ?string $description = 'Add or correct a structured address on a customer organization. An organization has one '
        . 'address per type (Billing, Shipping, Headquarters, ...): sending a type it already has updates that '
        . 'address, and only the fields you pass change.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'organization_id', type: PropertyType::INTEGER, description: 'The id of the organization.', required: true),
            new ToolProperty(
                name: 'type',
                type: PropertyType::STRING,
                description: 'Address type. Defaults to Billing.',
                required: false,
                enum: array_column(AddressTypeEnum::cases(), 'value'),
            ),
            new ToolProperty(name: 'address', type: PropertyType::STRING, description: 'Street address.', required: false),
            new ToolProperty(name: 'address_2', type: PropertyType::STRING, description: 'Suite, floor, sector.', required: false),
            new ToolProperty(name: 'city', type: PropertyType::STRING, description: 'City.', required: false),
            new ToolProperty(name: 'state', type: PropertyType::STRING, description: 'State / province.', required: false),
            new ToolProperty(name: 'zip', type: PropertyType::STRING, description: 'Postal code.', required: false),
            new ToolProperty(name: 'country', type: PropertyType::STRING, description: 'Country name, e.g. "Dominican Republic".', required: false),
            new ToolProperty(name: 'is_default', type: PropertyType::BOOLEAN, description: 'Make this the organization\'s main address.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $organization_id,
        ?string $type = null,
        ?string $address = null,
        ?string $address_2 = null,
        ?string $city = null,
        ?string $state = null,
        ?string $zip = null,
        ?string $country = null,
        ?bool $is_default = null,
    ): array {
        $typeEnum = AddressTypeEnum::tryFrom(trim($type ?? AddressTypeEnum::BILLING->value));
        if ($typeEnum === null) {
            return $this->invalidArgs(sprintf('Unknown address type "%s".', $type));
        }

        $result = $this->resolveOrganization($organization_id, null);
        if (is_array($result)) {
            return $result;
        }
        $organization = $result;

        $countryId = $this->resolveCountryIdOrError($country);
        if (is_array($countryId)) {
            return $countryId;
        }

        $existing = $organization->addresses()
            ->where('address_type_id', AddressType::getByName($typeEnum->value, $organization->app)->getId())
            ->first();

        $stored = new AddAddressToOrganizationAction(new AddressData(
            organization: $organization,
            type: $typeEnum,
            address: Str::trimToNull($address) ?? $existing?->address,
            address_2: Str::trimToNull($address_2) ?? $existing?->address_2,
            city: Str::trimToNull($city) ?? $existing?->city,
            county: $existing?->county,
            state: Str::trimToNull($state) ?? $existing?->state,
            zip: Str::trimToNull($zip) ?? $existing?->zip,
            countries_id: $countryId ?? $existing?->countries_id,
            city_id: $existing?->city_id,
            state_id: $existing?->state_id,
            latitude: $existing?->latitude,
            longitude: $existing?->longitude,
            is_default: $is_default ?? (bool) $existing?->is_default,
            user: $this->user,
        ))->execute();

        return $this->ok([
            'organization_id' => $organization->getId(),
            'address' => $this->presentAddress($stored),
            'message' => $existing !== null ? 'Address updated.' : 'Address saved.',
        ]);
    }
}
