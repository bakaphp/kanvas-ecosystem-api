<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Baka\Support\Str;
use Kanvas\Guild\Organizations\Actions\UpdateOrganizationAction;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationType;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Concerns\ChecksOrganizationTaxId;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
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
    use ChecksOrganizationTaxId;
    use HasKanvasContext;
    use ReportsToolOutcome;

    protected string $name = 'update_organization';

    protected ?string $description = 'Update a customer or vendor organization (company / account). Identify it by organization_id. '
        . 'Only the fields you provide are changed — name, email, phone, address, state, organization_type_id, '
        . 'tax_id (RNC in the Dominican Republic) or tax_id_type; the rest are left as-is. A tax id is checked '
        . 'before saving: a Dominican RNC/cédula is verified at DGII and the registered legal name comes back as '
        . 'dgii_legal_name; a refused one changes nothing. Use create_organization to make a new one.';

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
            ...$this->taxIdProperties(),
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
        ?string $tax_id = null,
        ?string $tax_id_type = null,
    ): array {
        try {
            /** @var Organization $organization */
            $organization = Organization::getByIdFromCompanyApp($organization_id, $this->company, $this->app);
        } catch (Throwable) {
            return $this->notFound(['error' => sprintf('No organization #%d found in this company.', $organization_id)]);
        }

        $name = $name !== null ? trim($name) : null;

        $checkedTaxId = null;
        $taxIdToCheck = Str::trimToNull($tax_id) ?? (Str::trimToNull($tax_id_type) !== null ? $organization->tax_id : null);
        if ($taxIdToCheck !== null) {
            $checkedTaxId = $this->checkTaxId($taxIdToCheck, $tax_id_type);
            if (($checkedTaxId['success'] ?? null) === false) {
                return $checkedTaxId;
            }
        }

        try {
            $organizationType = $organization_type_id !== null
                ? OrganizationType::getByIdFromCompanyApp($organization_type_id, $this->company, $this->app)
                : ($organization->organization_type_id !== null
                    ? OrganizationType::getByIdFromCompanyApp($organization->organization_type_id, $this->company, $this->app)
                    : null);
        } catch (Throwable) {
            return $this->notFound(['error' => sprintf('No organization type #%d found in this company.', $organization_type_id)]);
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
                    taxId: $checkedTaxId['tax_id'] ?? null,
                    taxIdType: $checkedTaxId['tax_id_type'] ?? null,
                ),
            )->execute();
        } catch (Throwable $e) {
            report($e);

            return $this->failed($e->getMessage(), guidance: 'Say the organization was NOT updated.');
        }

        return [
            'organization_id' => $organization->getId(),
            'name' => $organization->name,
            ...$organization->taxIdSummary(),
            ...$this->verificationResult($checkedTaxId),
            'message' => 'Organization updated.',
        ];
    }
}
