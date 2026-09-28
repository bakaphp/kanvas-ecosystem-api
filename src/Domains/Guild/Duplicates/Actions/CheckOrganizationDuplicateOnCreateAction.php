<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Actions;

use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\FindOrganizationDuplicatesService;

class CheckOrganizationDuplicateOnCreateAction
{
    public function __construct(
        public readonly Organization $organization,
    ) {
    }

    public function execute(): int
    {
        $groups = new FindOrganizationDuplicatesService()->checkRecord($this->organization);

        $upserter = new UpsertDuplicateReviewGroupAction(
            entityType: Organization::class,
            appsId: (int) $this->organization->apps_id,
            companiesId: (int) $this->organization->companies_id,
        );

        $created = 0;
        foreach ($groups as $group) {
            if ($upserter->execute($group)) {
                $created++;
            }
        }

        return $created;
    }
}
