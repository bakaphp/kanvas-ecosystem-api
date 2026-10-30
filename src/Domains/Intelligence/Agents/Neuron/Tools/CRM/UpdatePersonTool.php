<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Guild\Customers\Actions\UpdatePeopleProfileAction;
use Kanvas\Guild\Customers\Models\PeopleType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesPersonForTool;
use Kanvas\Intelligence\Tools\Traits\AnnouncesAgentEdits;
use Kanvas\Intelligence\Tools\Traits\Guild\LinksOrganizationByEmailDomain;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Updates a person's core profile fields (name, dob, title, people type). Non-destructive: it does NOT
 * touch the person's emails/phones — use manage_person_contact for those, and set_person_custom_fields
 * for arbitrary custom fields. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Update Person', category: 'crm')]
class UpdatePersonTool extends Tool
{
    use AnnouncesAgentEdits;
    use HasKanvasContext;
    use LinksOrganizationByEmailDomain;
    use ReportsToolOutcome;
    use ResolvesPersonForTool;

    protected string $name = 'update_person';

    protected ?string $description = 'Update a contact\'s core fields: firstname, lastname, middlename, date of birth, title or '
        . 'people type (e.g. participant, facilitator). '
        . 'Only the fields you pass are changed; emails/phones are left untouched (use manage_person_contact '
        . 'for those). A person with no organization is linked to the one their email domain points at: '
        . 'one match is linked for you, several come back as organization_candidates for you to ask about. '
        . 'Identify the person by person_id (use find_person to get it).';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'person_id', type: PropertyType::INTEGER, description: 'The id of the person to update.', required: true),
            new ToolProperty(name: 'firstname', type: PropertyType::STRING, description: 'New first name.', required: false),
            new ToolProperty(name: 'lastname', type: PropertyType::STRING, description: 'New last name.', required: false),
            new ToolProperty(name: 'middlename', type: PropertyType::STRING, description: 'New middle name.', required: false),
            new ToolProperty(name: 'dob', type: PropertyType::STRING, description: 'Date of birth as YYYY-MM-DD.', required: false),
            new ToolProperty(name: 'title', type: PropertyType::STRING, description: 'Job title / role.', required: false),
            new ToolProperty(
                name: 'people_type',
                type: PropertyType::STRING,
                description: 'Name of an existing people type in this company, e.g. "Participant" or "Facilitator".',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $person_id,
        ?string $firstname = null,
        ?string $lastname = null,
        ?string $middlename = null,
        ?string $dob = null,
        ?string $title = null,
        ?string $people_type = null,
    ): array {
        $result = $this->resolvePersonOrError($person_id);
        if (is_array($result)) {
            return $result;
        }
        $person = $result;

        $peopleType = null;
        $peopleTypeName = Str::trimToNull($people_type);
        if ($peopleTypeName !== null) {
            try {
                $peopleType = PeopleType::getByNameFromCompanyApp($peopleTypeName, $this->company, $this->app);
            } catch (Throwable) {
                return $this->notFound([
                    'error' => sprintf('No people type named "%s" in this company. Nothing was changed.', $peopleTypeName),
                ]);
            }
        }

        try {
            $person = new UpdatePeopleProfileAction(
                $person,
                $this->app,
                firstname: $firstname,
                lastname: $lastname,
                middlename: $middlename,
                dob: $dob,
            )->execute();

            if ($title !== null && trim($title) !== '') {
                $person->set('title', trim($title));
            }

            if ($peopleType !== null) {
                $person->people_types_id = $peopleType->getId();
                $person->saveOrFail();
            }
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        $organization = $this->organizationFromEmailDomain($person);

        // A call that sets nothing still saves the row; it must not open an approval for an unchanged person.
        $changed = array_filter(
            [$firstname, $lastname, $middlename, $dob, Str::trimToNull($title), $peopleType, $organization['organization'] ?? null],
            static fn (mixed $value): bool => $value !== null
        );
        if ($changed !== []) {
            $this->announceAgentEdit($person);
        }

        return [
            'person_id' => $person->getId(),
            'name' => $person->getName(),
            'title' => $person->get('title') ?: null,
            'people_type' => $person->peopleType?->name,
            ...$organization,
            'message' => 'Contact updated.',
        ];
    }
}
