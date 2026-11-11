<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Workflow\Enums\WorkflowEnum;

class UpdateOrganizationAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected Organization $organization,
        protected readonly OrganizationData $organizationData
    ) {
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): Organization
    {
        $attributes = [
            'name' => $this->organizationData->name,
            'email' => $this->organizationData->email,
            'phone' => $this->organizationData->phone,
            'address' => $this->organizationData->address,
            'organization_type_id' => $this->organizationData->organizationType?->getId(),
        ];

        if ($this->organizationData->taxId !== null) {
            $attributes['tax_id'] = $this->organizationData->taxId;
        }

        if ($this->organizationData->taxIdType !== null) {
            $attributes['tax_id_type'] = $this->organizationData->taxIdType;
        }

        $this->organization->update($attributes);

        $this->organization->fireWorkflow(
            WorkflowEnum::UPDATED->value,
            params: ['app' => $this->organizationData->app],
        );

        return $this->organization;
    }
}
