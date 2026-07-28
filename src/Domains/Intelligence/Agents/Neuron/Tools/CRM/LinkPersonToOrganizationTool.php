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
 * Links a person to a customer organization (employment). Writes the organizations_peoples pivot and,
 * when a title is given, an employment-history row. The write counterpart of list_organization_people.
 * Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Link Person To Organization', category: 'crm')]
class LinkPersonToOrganizationTool extends Tool
{
    use HasKanvasContext;
    use ResolvesOrganizationForTool;

    public function __construct()
    {
        parent::__construct(
            name: 'link_person_to_organization',
            description: 'Associate a person with a customer organization (i.e. record where they work). Pass '
                . 'person_id plus organization_id (preferred) or organization_name, and optionally a title. Resolves '
                . 'ambiguous org names to candidates. Use list_organization_people to read the links back.',
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
            new ToolProperty(name: 'title', type: PropertyType::STRING, description: 'The person\'s title/position at this organization. Records an employment entry when given.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $person_id, ?int $organization_id = null, ?string $organization_name = null, ?string $title = null): array
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

        OrganizationPeople::addPeopleToOrganization($organization, $person);

        $title = $title !== null ? trim($title) : null;
        if ($title !== null && $title !== '') {
            PeopleEmploymentHistory::firstOrCreate([
                'apps_id' => $this->app->getId(),
                'peoples_id' => $person->getId(),
                'organizations_id' => $organization->getId(),
                'position' => $title,
                'status' => 1,
            ]);
        }

        return [
            'person_id' => $person->getId(),
            'organization' => [
                'organization_id' => $organization->getId(),
                'name' => $organization->name,
            ],
            'title' => ($title !== null && $title !== '') ? $title : null,
            'message' => 'Person linked to organization.',
        ];
    }
}
