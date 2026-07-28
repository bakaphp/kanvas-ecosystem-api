<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Customers\Actions\UpdatePeopleProfileAction;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Updates a person's core profile fields (name, dob, title). Non-destructive: it does NOT touch the
 * person's emails/phones — use manage_person_contact for those, and set_person_custom_fields for
 * arbitrary custom fields. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Update Person', category: 'crm')]
class UpdatePersonTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'update_person',
            description: 'Update a contact\'s core fields: firstname, lastname, middlename, date of birth, or title. '
                . 'Only the fields you pass are changed; emails/phones are left untouched (use manage_person_contact '
                . 'for those). Identify the person by person_id (use find_person to get it).',
        );
    }

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
    ): array {
        try {
            /** @var People $person */
            $person = People::getByIdFromCompanyApp($person_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No person #%d found in this company.', $person_id)];
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
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'person_id' => $person->getId(),
            'name' => $person->getName(),
            'title' => $person->get('title') ?: null,
            'message' => 'Contact updated.',
        ];
    }
}
