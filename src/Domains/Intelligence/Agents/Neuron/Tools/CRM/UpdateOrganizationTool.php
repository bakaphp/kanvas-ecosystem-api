<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Organizations\Actions\UpdateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Throwable;

/**
 * Updates a customer organization's fields. Non-destructive: only the fields you pass change, the rest
 * keep their current values. Identify the organization by organization_id (use find_customer or
 * list_organization_people to get it). Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Update Organization', category: 'crm')]
class UpdateOrganizationTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'update_organization',
            description: 'Update a customer organization (company / account). Identify it by organization_id. Only '
                . 'the fields you provide are changed — name, email, phone, address, state or organization_type_id; '
                . 'the rest are left as-is. Use create_organization to make a new one.',
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
                description: 'The id of the organization to update.',
                required: true,
            ),
            new ToolProperty(name: 'name', type: PropertyType::STRING, description: 'New organization name.', required: false),
            new ToolProperty(name: 'email', type: PropertyType::STRING, description: 'Contact email.', required: false),
            new ToolProperty(name: 'phone', type: PropertyType::STRING, description: 'Contact phone number.', required: false),
            new ToolProperty(name: 'address', type: PropertyType::STRING, description: 'Street address.', required: false),
            new ToolProperty(name: 'state', type: PropertyType::STRING, description: 'State / province / region.', required: false),
            new ToolProperty(
                name: 'organization_type_id',
                type: PropertyType::INTEGER,
                description: 'The id of an existing organization type to categorize this organization.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        int $organization_id,
        ?string $name = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $state = null,
        ?int $organization_type_id = null,
    ): array {
        try {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp($organization_id, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No organization #%d found in this company.', $organization_id)];
        }

        $name = $name !== null ? trim($name) : null;

        try {
            $organizationType = $organization_type_id !== null
                ? OrganizationType::getByIdFromCompanyApp($organization_type_id, $this->company, $this->app)
                : ($organization->organization_type_id !== null
                    ? OrganizationType::getByIdFromCompanyApp($organization->organization_type_id, $this->company, $this->app)
                    : null);
        } catch (Throwable) {
            return ['error' => sprintf('No organization type #%d found in this company.', $organization_type_id)];
        }

        try {
            $organization = new UpdateOrganizationAction(
                $organization,
                new OrganizationData(
                    company: $this->company,
                    user: $this->user,
                    app: $this->app,
                    name: $name !== null && $name !== '' ? $name : $organization->name,
                    email: $email !== null ? trim($email) : $organization->email,
                    phone: $phone !== null ? trim($phone) : $organization->phone,
                    address: $address !== null ? trim($address) : $organization->address,
                    state: $state !== null ? trim($state) : $organization->state,
                    organizationType: $organizationType,
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }

        return [
            'organization_id' => $organization->getId(),
            'name' => $organization->name,
            'message' => 'Organization updated.',
        ];
    }
}
