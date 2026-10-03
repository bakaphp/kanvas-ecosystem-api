<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Organizations\Models\Address;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExposesCustomFields;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HandlesAddressesForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Get Organization', category: 'crm')]
class GetOrganizationTool extends Tool implements HasRunKey
{
    use ExposesCustomFields;
    use HandlesAddressesForTool;
    use ResolvesOrganizationForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'get_organization',
            description: 'Returns the full profile of one customer organization: name, email, phone, type, addresses, '
                . 'tags, how many people are linked to it, and its business custom fields. Identify it by '
                . 'organization_id, or by organization_name when you do not have the id.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'organization_id', type: PropertyType::INTEGER, description: 'The id of the organization.', required: false),
            new ToolProperty(name: 'organization_name', type: PropertyType::STRING, description: 'The organization name, when the id is unknown.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $organization_id = null, ?string $organization_name = null): array
    {
        $result = $this->resolveOrganization($organization_id, $organization_name);
        if (is_array($result)) {
            return $result;
        }
        $organization = $result;

        $organization->load(['organizationType', 'addresses.type', 'addresses.country', 'tags']);

        return [
            'organization_id' => $organization->getId(),
            'name' => $organization->name,
            'email' => $organization->email,
            'phone' => $organization->phone,
            'address' => $organization->address,
            'organization_type' => $organization->organizationType?->name,
            'addresses' => $organization->addresses
                ->map(fn (Address $a): array => $this->presentAddress($a))
                ->values()->all(),
            'tags' => $organization->tags->pluck('name')->all(),
            'people_count' => $organization->peoples()->count(),
            'custom_fields' => $this->relevantCustomFields($organization),
        ];
    }
}
