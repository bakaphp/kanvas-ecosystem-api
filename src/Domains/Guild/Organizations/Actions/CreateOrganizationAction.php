<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;
use Kanvas\Workflow\Enums\WorkflowEnum;

class CreateOrganizationAction
{
    public function __construct(
        protected readonly OrganizationData $organizationData
    ) {
    }

    public function execute(): Organization
    {
        $name = Str::limit(
            OrganizationNameNormalizerService::normalize($this->organizationData->name),
            128,
            ''
        );

        $organization = Organization::firstOrCreate([
            'name' => $name,
            'companies_id' => $this->organizationData->company->getId(),
            'apps_id' => $this->organizationData->app->getId(),
        ], [
            'address' => $this->organizationData->address,
            'users_id' => $this->organizationData->user->getId(),
            'email' => $this->organizationData->email,
            'phone' => $this->organizationData->phone,
            'state' => $this->organizationData->state,
            'organization_type_id' => $this->organizationData->organizationType?->getId(),
            'tax_id' => $this->organizationData->taxId,
            'tax_id_type' => $this->organizationData->taxIdType,
        ]);

        // A name match is the same party; a tax id we just learned for it is filled, never overwritten.
        if (! $organization->wasRecentlyCreated
            && $organization->tax_id === null
            && Str::normalizeTaxId($this->organizationData->taxId) !== null
        ) {
            $organization->tax_id = $this->organizationData->taxId;
            $organization->tax_id_type = $this->organizationData->taxIdType;
            $organization->saveOrFail();
        }

        // firstOrCreate, so this is a find as often as it is a create. Only the create is news.
        if ($organization->wasRecentlyCreated && $this->organizationData->runWorkflow) {
            $organization->fireWorkflow(
                WorkflowEnum::CREATED->value,
                params: ['app' => $this->organizationData->app],
            );
        }

        return $organization;
    }
}
