<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ExtractsPersonContacts;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'List People', category: 'crm')]
class ListPeopleTool extends Tool implements HasRunKey
{
    use ExtractsPersonContacts;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'list_people',
            description: 'List and filter people by organization name, tag, and/or person type. Use for "everyone at '
                . '<company>", "all people tagged <X>", "all facilitators/participants". Combine filters to narrow. '
                . 'For a name/email lookup use find_person; for one company\'s people use list_organization_people.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'organization', type: PropertyType::STRING, description: 'Company/organization name (partial match). Filters to people linked to a matching org.', required: false),
            new ToolProperty(name: 'tag', type: PropertyType::STRING, description: 'Tag name (partial match) the person must carry.', required: false),
            new ToolProperty(name: 'people_type', type: PropertyType::STRING, description: 'Person type name, e.g. "Participant", "Facilitator" (partial match).', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max people to return. Defaults to 50, max 200.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $organization = null,
        ?string $tag = null,
        ?string $people_type = null,
        ?int $limit = null,
    ): array {
        $organization = $organization !== null ? trim($organization) : null;
        $tag = $tag !== null ? trim($tag) : null;
        $people_type = $people_type !== null ? trim($people_type) : null;

        if (($organization === null || $organization === '')
            && ($tag === null || $tag === '')
            && ($people_type === null || $people_type === '')) {
            return ['error' => 'Provide at least one filter: organization, tag, or people_type.'];
        }

        $limit = max(1, min(200, $limit ?? 50));

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->when($organization !== null && $organization !== '', fn ($q): mixed => $q->whereHas(
                'organizations',
                fn ($o): mixed => $o->where('name', 'like', '%' . $organization . '%'),
            ))
            ->when($tag !== null && $tag !== '', fn ($q): mixed => $q->whereHas(
                'tags',
                fn ($t): mixed => $t->where('name', 'like', '%' . $tag . '%'),
            ))
            ->when($people_type !== null && $people_type !== '', fn ($q): mixed => $q->whereHas(
                'peopleType',
                fn ($p): mixed => $p->where('name', 'like', '%' . $people_type . '%'),
            ))
            ->with([
                'contacts',
                'organizations' => fn ($q): mixed => $q->select('organizations.id', 'name'),
                'peopleType',
            ])
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'firstname', 'lastname', 'name', 'people_types_id']);

        return [
            'count' => $people->count(),
            'people' => $people->map(fn (People $person): array => [
                'person_id' => $person->getId(),
                'name' => $person->getName(),
                'email' => $this->primaryEmail($person),
                'organization' => $person->organizations->first()?->name,
                'people_type' => $person->peopleType?->name,
            ])->all(),
        ];
    }
}
