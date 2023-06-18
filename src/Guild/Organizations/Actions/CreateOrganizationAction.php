<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;

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
        return Organization::firstOrCreate([
            'name' => $this->organizationData->name,
            'companies_id' => $this->organizationData->company->getId(),
        ], [
            'description' => $this->organizationData->description,
            'users_id' => $this->organizationData->user->getId(),
        ]);
    }
}
