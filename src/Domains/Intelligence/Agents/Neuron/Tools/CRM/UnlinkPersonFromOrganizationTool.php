<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesOrganizationForTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Removes the association between a person and a customer organization (employment). Deletes the
 * organizations_peoples pivot and closes any open employment-history rows for that org. The inverse of
 * link_person_to_organization. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Unlink Person From Organization', category: 'crm')]
class UnlinkPersonFromOrganizationTool extends Tool
{
    use HasKanvasContext;
    use ResolvesOrganizationForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'unlink_person_from_organization',
            description: 'Remove a person\'s association with a customer organization (i.e. they no longer work / '
                . 'belong there). Pass person_id plus organization_id (preferred) or organization_name. Since a person '
                . 'can belong to several organizations, this only removes the one you name — the others stay. Resolves '
                . 'ambiguous org names to candidates. Use list_organization_people to read the remaining links back.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'The id of the person.', required: true),
            new ToolProperty(name: 'organization_id', type: PropertyType::INTEGER, description: 'The organization id. Preferred when known.', required: false),
            new ToolProperty(name: 'organization_name', type: PropertyType::STRING, description: 'The organization name, when you do not have the id.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id, ?int $organization_id = null, ?string $organization_name = null): array
    {
        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $person_id)];
        }

        $organization = $this->resolveOrganization($organization_id, $organization_name);
        if (! $organization instanceof Organization) {
            return $organization;
        }

        $removed = OrganizationPeople::removePeopleFromOrganization($organization, $person);

        if ($removed === 0) {
            return [
                'person_id' => $person->getId(),
                'organization' => [
                    'organization_id' => $organization->getId(),
                    'name' => $organization->name,
                ],
                'message' => 'This person was not linked to that organization — nothing to remove.',
            ];
        }

        PeopleEmploymentHistory::query()
            ->where('apps_id', $this->app->getId())
            ->where('peoples_id', $person->getId())
            ->where('organizations_id', $organization->getId())
            ->whereNull('end_date')
            ->update([
                'status' => 0,
                'end_date' => now()->toDateString(),
            ]);

        return [
            'person_id' => $person->getId(),
            'organization' => [
                'organization_id' => $organization->getId(),
                'name' => $organization->name,
            ],
            'message' => 'Person unlinked from organization.',
        ];
    }
}
