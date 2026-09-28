<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExposesPersonCustomFields;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExtractsPersonContacts;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'List Organization People', category: 'crm')]
class ListOrganizationPeopleTool extends Tool implements HasRunKey
{
    use ExposesPersonCustomFields;
    use ExtractsPersonContacts;
    use HasKanvasContext;
    use ResolvesOrganizationForTool;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_organization_people',
            description: 'Lists the people (employees / contacts) associated with a customer organization. Use for '
                . '"who works at Acme?", "show me the contacts at MCTEKK", "list the people at organization 111695". '
                . 'Pass organization_id when you have it (e.g. from find_customer), otherwise organization_name and '
                . 'this resolves it — returning candidates to confirm if the name is ambiguous.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'organization_id',
                type: PropertyType::INTEGER,
                description: 'The organization id to list people for. Preferred when known.',
                required: false,
            ),
            new ToolProperty(
                name: 'organization_name',
                type: PropertyType::STRING,
                description: 'The organization name to resolve, when you do not have the id. Ignored if organization_id is given.',
                required: false,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max people to return, most recent first. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?int $organization_id = null, ?string $organization_name = null, ?int $limit = null): array
    {
        $limit = max(1, min(100, $limit ?? 25));

        $organization = $this->resolveOrganization($organization_id, $organization_name);
        if (! $organization instanceof Organization) {
            return $organization;
        }

        $total = (int) $organization->peoples()->where('peoples.is_deleted', 0)->count();

        $people = $organization->peoples()
            ->where('peoples.is_deleted', 0)
            ->with(['contacts'])
            ->orderByDesc('peoples.id')
            ->limit($limit)
            ->get();

        return [
            'organization' => [
                'organization_id' => $organization->getId(),
                'name' => $organization->name,
            ],
            'count' => $total,
            'returned' => $people->count(),
            'people' => $people->map(fn (People $person): array => [
                'person_id' => $person->getId(),
                'name' => $person->getName(),
                'email' => $this->primaryEmail($person),
                'title' => $person->get('title') ?: null,
                'custom_fields' => $this->relevantCustomFields($person),
            ])->all(),
        ];
    }
}
