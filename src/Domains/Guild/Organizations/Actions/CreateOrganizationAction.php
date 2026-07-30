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
        ]);

        // firstOrCreate, so this is a find as often as it is a create. Only the create is news.
        if ($organization->wasRecentlyCreated) {
            $organization->fireWorkflow(
                WorkflowEnum::CREATED->value,
                params: ['app' => $this->organizationData->app],
            );
        }

        return $organization;
    }
}
