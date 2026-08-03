<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Actions;

use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\FindPeopleDuplicatesService;

class CheckPeopleDuplicateOnCreateAction
{
    public function __construct(
        public readonly People $people,
    ) {
    }

    public function execute(): int
    {
        $groups = new FindPeopleDuplicatesService()->checkRecord($this->people);

        $upserter = new UpsertDuplicateReviewGroupAction(
            entityType: People::class,
            appsId: (int) $this->people->apps_id,
            companiesId: (int) $this->people->companies_id,
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
