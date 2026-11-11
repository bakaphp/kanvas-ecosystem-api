<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\DecodesJsonObjectParam;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\LinksRecordsToAdmin;
use Kanvas\Intelligence\Tools\Traits\Guild\LinksOrganizationByEmailDomain;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
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
    use LinksOrganizationByEmailDomain;
    use LinksRecordsToAdmin;
    use ReportsToolOutcome;
    use TrackByInputs;

    protected string $name = 'create_person';

    protected ?string $description = 'Create a new contact (person) in the CRM without creating a lead. Provide at least a '
        . 'firstname; add email, phone, title, organization name, tags and custom fields as known. When '
        . 'you give an email and no organization, the organization is deduced from the email domain: one '
        . 'match is linked for you, several come back as organization_candidates for you to ask about. '
        . 'If a contact with the same email/phone already exists it is updated, not duplicated. Returns '
        . 'the person_id. It never creates a sales lead.';

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
                description: 'Employer / organization name. Created and linked if it does not exist yet. Leave it '
                    . 'empty when unknown: a company email domain resolves it.',
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
            return $this->invalidArgs('firstname is required.');
        }

        $email = Str::trimToNull($email);
        $phone = Str::trimToNull($phone);
        $title = Str::trimToNull($title);
        $organization = Str::trimToNull($organization);

        $contacts = [];
        if ($email !== null) {
            $contacts[] = ['value' => $email];
        }
        if ($phone !== null) {
            $contacts[] = ['value' => $phone];
        }

        $customFields = $custom_fields;
        if ($title !== null) {
            $customFields['title'] = $title;
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
                'organization' => $organization,
                'tags' => $tags ?? [],
                'custom_fields' => $customFields,
            ]);

            $person = new CreatePeopleAction($dto)->execute();
        } catch (Throwable $e) {
            return $this->failed($e->getMessage(), guidance: 'Say the contact was NOT created.');
        }

        return [
            'person_id' => $person->getId(),
            'admin_url' => $this->adminUrlOf($person),
            'name' => $person->getName(),
            'email' => $person->getEmails()->first()?->value,
            // Linked after the create, not passed as the DTO's organization name: that path goes through
            // CreateOrganizationAction's normalize + firstOrCreate, so "Acme, Inc." would come back as a
            // second "Acme" instead of the organization the domain points at.
            ...($organization === null && $email !== null ? $this->organizationFromEmailDomain($person) : []),
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
