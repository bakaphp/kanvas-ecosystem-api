<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Guild\Organizations\Actions\CreateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\OrganizationType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Creates a customer organization (company/account). Dedup is automatic on the normalized name, so a
 * matching organization is returned instead of a duplicate. Use update_organization to change an
 * existing one. Company-wide write — an internal-teammate capability.
 */
#[AgentTool(name: 'Create Organization', category: 'crm')]
class CreateOrganizationTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'create_organization',
            description: 'Create a customer organization (company / account) in the CRM. name is required; add '
                . 'email, phone, address, state and organization_type_id as known. If an organization with the same '
                . 'name already exists it is returned rather than duplicated (created will be false). Returns the '
                . 'organization_id. Use update_organization to change an organization you already have the id for.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'name', type: PropertyType::STRING, description: 'Organization name (required).', required: true),
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
        string $name,
        ?string $email = null,
        ?string $phone = null,
        ?string $address = null,
        ?string $state = null,
        ?int $organization_type_id = null,
    ): array {
        $name = trim($name);
        if ($name === '') {
            return ['error' => 'name is required to create an organization.'];
        }

        $organizationType = null;
        if ($organization_type_id !== null) {
            try {
                /** @var OrganizationType $organizationType */
                $organizationType = OrganizationType::getByIdFromCompanyApp($organization_type_id, $this->company, $this->app);
            } catch (Throwable) {
                return ['error' => sprintf('No organization type #%d found in this company.', $organization_type_id)];
            }
        }

        try {
            $organization = new CreateOrganizationAction(
                new OrganizationData(
                    company: $this->company,
                    user: $this->user,
                    app: $this->app,
                    name: $name,
                    email: $email !== null ? trim($email) : null,
                    phone: $phone !== null ? trim($phone) : null,
                    address: $address !== null ? trim($address) : null,
                    state: $state !== null ? trim($state) : null,
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
}
