<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\Actions;

use Kanvas\Guild\Organizations\DataTransferObject\Organization as OrganizationData;
use Kanvas\Guild\Organizations\Models\Organization;

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
        $this->organization->update([
            'name' => $this->organizationData->name,
            'address' => $this->organizationData->address,
        ]);

        return $this->organization;
    }
}
