<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Baka\Support\Str;
use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\OrganizationNameNormalizerService;

class CreateOrganizationAction
{
    /**
     * __construct.
     */
    public function __construct(
        protected readonly OrganizationData $organizationData
    ) {
    }

    /**
     * @psalm-suppress MixedReturnStatement
     */
    public function execute(): Organization
    {
        $name = Str::limit(
            OrganizationNameNormalizerService::normalize($this->organizationData->name),
            128,
            ''
        );

        return Organization::firstOrCreate([
            'name' => $name,
            'companies_id' => $this->organizationData->company->getId(),
            'apps_id' => $this->organizationData->app->getId(),
        ], [
            'address' => $this->organizationData->address,
            'users_id' => $this->organizationData->user->getId(),
            'email' => $this->organizationData->email,
            'state' => $this->organizationData->state,
            'organization_type_id' => $this->organizationData->organizationType?->getId(),
        ]);
    }
}
