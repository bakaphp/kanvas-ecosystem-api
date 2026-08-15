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

/**
 * Finds people (contacts) in the CRM directory directly — by name, email, or phone — without needing
 * a lead or an organization. Fills the gap where a contact who isn't attached to a lead was otherwise
 * unreachable. Returns candidates with a person_id to hand to get_person or other person tools.
 * Company-wide read — an internal-teammate capability, NOT the customer-facing prospect surface.
 */
#[AgentTool(name: 'Find Person', category: 'crm')]
class FindPersonTool extends Tool implements HasRunKey
{
    use ExtractsPersonContacts;
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'find_person',
            description: 'Search the people/contacts directory for ONE person by (partial) name, email, or phone. Use '
                . 'whenever you need to locate a single person and do not have their person_id — "find John", "who has '
                . 'this email", "look up the contact for +1809...". Returns person_id, name, email, phone and '
                . 'organization for each match. Use get_person for the full profile of one match. '
                . 'For MORE THAN ONE name — a spreadsheet column, a CSV, any list — use find_people_bulk instead and '
                . 'pass every name in a single call; do not call this tool once per row.',
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
                name: 'query',
                type: PropertyType::STRING,
                description: 'Name, email, or phone to search for.',
                required: true,
            ),
            new ToolProperty(
                name: 'limit',
                type: PropertyType::INTEGER,
                description: 'Max people to return, most recently updated first. Defaults to 25, max 100.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(string $query, ?int $limit = null): array
    {
        $query = trim($query);
        if ($query === '') {
            return ['count' => 0, 'people' => [], 'error' => 'Provide a name, email, or phone to search for.'];
        }

        $limit = max(1, min(100, $limit ?? 25));
        $like = '%' . $query . '%';

        $people = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->where(function ($q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('firstname', 'like', $like)
                    ->orWhere('lastname', 'like', $like)
                    ->orWhereHas('contacts', fn ($c) => $c->where('value', 'like', $like));
            })
            ->with(['contacts', 'organizations' => fn ($q) => $q->select('organizations.id', 'name')])
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get(['id', 'firstname', 'lastname', 'name', 'updated_at']);

        return [
            'count' => $people->count(),
            'people' => $people->map(fn (People $person): array => [
                'person_id' => $person->getId(),
                'name' => $person->getName(),
                'email' => $this->primaryEmail($person),
                'phone' => $this->primaryPhone($person),
                'organization' => $person->organizations->first()?->name,
                'last_updated' => $person->updated_at?->toDateString(),
            ])->all(),
        ];
    }
}
