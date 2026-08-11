<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
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
 * Creates or updates a customer organization (company/account). With no organization_id it creates —
 * dedup is automatic on the normalized name, so an existing match is returned instead of a duplicate.
 * With an organization_id it updates that record, overlaying only the fields you pass so the rest keep
 * their current values. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Create Or Update Organization', category: 'crm')]
class CreateOrUpdateOrganizationTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'create_or_update_organization',
            description: 'Create or update a customer organization (company / account) in the CRM. Omit '
                . 'organization_id to create — if an organization with the same name already exists it is returned '
                . 'rather than duplicated. Pass organization_id to update an existing one; only the fields you '
                . 'provide change, the rest are left as-is. name is required when creating. Optionally set email, '
                . 'phone, address, state and organization_type_id. Returns the organization_id. Use find_customer or '
                . 'list_organization_people to look up an id first.',
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
                description: 'The id of the organization to update. Omit to create a new one.',
                required: false,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Organization name. Required when creating; on update it renames the organization.',
                required: false,
            ),
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
        ?int $organization_id = null,
        ?string $name = null,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $state = null,
        ?int $organization_type_id = null,
    ): array {
        $name = $name !== null ? trim($name) : null;
        $email = $email !== null ? trim($email) : null;
        $phone = $phone !== null ? trim($phone) : null;
        $address = $address !== null ? trim($address) : null;
        $state = $state !== null ? trim($state) : null;

        $organizationType = null;
        if ($organization_type_id !== null) {
            try {
                /** @var OrganizationType $organizationType */
                $organizationType = OrganizationType::getByIdFromCompanyApp($organization_type_id, $this->company, $this->app);
            } catch (Throwable) {
                return ['error' => sprintf('No organization type #%d found in this company.', $organization_type_id)];
            }
        }

        return $organization_id !== null
            ? $this->update($organization_id, $name, $email, $phone, $address, $state, $organizationType)
            : $this->create($name, $email, $phone, $address, $state, $organizationType);
    }

    /**
     * @return array<string, mixed>
     */
    private function create(
        ?string $name,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $state,
        ?OrganizationType $organizationType,
    ): array {
        if ($name === null || $name === '') {
            return ['error' => 'name is required to create an organization.'];
        }

        try {
            $organization = new CreateOrganizationAction(
                new OrganizationData(
                    company: $this->company,
                    user: $this->user,
                    app: $this->app,
                    name: $name,
                    email: $email,
                    phone: $phone,
                    address: $address,
                    state: $state,
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
            'created' => $organization->wasRecentlyCreated,
            'message' => $organization->wasRecentlyCreated
                ? 'Organization created.'
                : 'A matching organization already existed and was returned.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function update(
        int $organizationId,
        ?string $name,
        ?string $email,
        ?string $phone,
        ?string $address,
        ?string $state,
        ?OrganizationType $organizationType,
    ): array {
        try {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp($organizationId, $this->company, $this->app);
        } catch (Throwable) {
            return ['error' => sprintf('No organization #%d found in this company.', $organizationId)];
        }

        try {
            $organization = new UpdateOrganizationAction(
                $organization,
                new OrganizationData(
                    company: $this->company,
                    user: $this->user,
                    app: $this->app,
                    name: $name !== null && $name !== '' ? $name : $organization->name,
                    email: $email ?? $organization->email,
                    phone: $phone ?? $organization->phone,
                    address: $address ?? $organization->address,
                    state: $state ?? $organization->state,
                    organizationType: $organizationType ?? ($organization->organization_type_id !== null
                        ? OrganizationType::getByIdFromCompanyApp($organization->organization_type_id, $this->company, $this->app)
                        : null),
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return ['error' => $e->getMessage()];
        }

        return [
            'organization_id' => $organization->getId(),
            'name' => $organization->name,
            'created' => false,
            'message' => 'Organization updated.',
        ];
    }
}
