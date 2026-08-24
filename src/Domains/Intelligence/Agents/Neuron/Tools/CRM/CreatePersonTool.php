<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use Override;
use Throwable;

/**
 * Creates a standalone contact (People) with no lead attached — use create_lead when you want a lead.
 * Dedup is automatic: if a matching email/phone already exists in the company it updates that record
 * instead of making a duplicate. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Create Person', category: 'crm')]
class CreatePersonTool extends Tool
{
    use DecodesJsonObjectParam;
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'create_person',
            description: 'Create a new contact (person) in the CRM without creating a lead. Provide at least a '
                . 'firstname; add email, phone, title, organization name, tags and custom fields as known. If a '
                . 'contact with the same email/phone already exists it is updated, not duplicated. Returns the '
                . 'person_id. Use create_lead instead when the person should also become a sales lead.',
        );
    }

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'firstname', type: PropertyType::STRING, description: 'First name (required).', required: true),
            new ToolProperty(name: 'lastname', type: PropertyType::STRING, description: 'Last name.', required: false),
            new ToolProperty(name: 'email', type: PropertyType::STRING, description: 'Email address.', required: false),
            new ToolProperty(name: 'phone', type: PropertyType::STRING, description: 'Phone number.', required: false),
            new ToolProperty(name: 'title', type: PropertyType::STRING, description: 'Job title / role.', required: false),
            new ToolProperty(
                name: 'organization',
                type: PropertyType::STRING,
                description: 'Employer / organization name. Created and linked if it does not exist yet.',
                required: false,
            ),
            new ArrayProperty(
                name: 'tags',
                description: 'Optional list of tag names to attach.',
                required: false,
                items: new ToolProperty(name: 'tag', type: PropertyType::STRING, description: 'A tag name.'),
            ),
            new ToolProperty(
                name: 'custom_fields',
                type: PropertyType::STRING,
                description: 'Optional JSON object mapping custom field name → value, passed as a string. '
                    . 'For example: {"seniority": "manager"}.',
                required: false,
            ),
        ];
    }

    /**
     * @param list<string>|null $tags
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $firstname,
        ?string $lastname = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $title = null,
        ?string $organization = null,
        ?array $tags = null,
        array|string|null $custom_fields = null,
    ): array {
        $custom_fields = $this->decodeJsonObjectParam($custom_fields);
        $firstname = trim($firstname);
        if ($firstname === '') {
            return ['error' => 'firstname is required.'];
        }

        $contacts = [];
        if ($email !== null && trim($email) !== '') {
            $contacts[] = ['value' => trim($email)];
        }
        if ($phone !== null && trim($phone) !== '') {
            $contacts[] = ['value' => trim($phone)];
        }

        $customFields = $custom_fields;
        if ($title !== null && trim($title) !== '') {
            $customFields['title'] = trim($title);
        }

        try {
            $dto = PeopleData::from([
                'app' => $this->app,
                'branch' => $this->resolveBranch(),
                'user' => $this->user,
                'firstname' => $firstname,
                'lastname' => $lastname,
                'contacts' => $contacts,
                'address' => [],
                'organization' => $organization !== null && trim($organization) !== '' ? trim($organization) : null,
                'tags' => $tags ?? [],
                'custom_fields' => $customFields,
            ]);

            $person = new CreatePeopleAction($dto)->execute();
        } catch (Throwable $e) {
            return ['error' => $e->getMessage()];
        }

        return [
            'person_id' => $person->getId(),
            'name' => $person->getName(),
            'email' => $person->getEmails()->first()?->value,
            'message' => 'Contact created.',
        ];
    }

    private function resolveBranch(): CompaniesBranches
    {
        /** @var CompaniesBranches $branch */
        $branch = $this->company->defaultBranch()->first() ?? $this->company->branches()->firstOrFail();

        return $branch;
    }
}
