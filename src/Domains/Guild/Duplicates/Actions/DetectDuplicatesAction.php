<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\FindPeopleDuplicatesService;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Services\FindOrganizationDuplicatesService;

/**
 * Runs both duplicate-finder services for one tenant and upserts new groups into
 * `duplicate_review_groups`. Idempotent: a group whose (entity_type, sorted member_ids) already
 * has a row — pending, dismissed, or otherwise — is skipped rather than re-inserted.
 */
class DetectDuplicatesAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
    ) {
    }

    /**
     * @return array{created: int, skipped: int}
     */
    public function execute(): array
    {
        $created = 0;
        $skipped = 0;

        $organizationUpserter = new UpsertDuplicateReviewGroupAction(Organization::class, $this->app->getId(), $this->company->getId());
        foreach (new FindOrganizationDuplicatesService()->generate($this->app, $this->company) as $group) {
            $organizationUpserter->execute($group) ? $created++ : $skipped++;
        }

        $peopleUpserter = new UpsertDuplicateReviewGroupAction(People::class, $this->app->getId(), $this->company->getId());
        foreach (new FindPeopleDuplicatesService()->generate($this->app, $this->company) as $group) {
            $peopleUpserter->execute($group) ? $created++ : $skipped++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }
}
