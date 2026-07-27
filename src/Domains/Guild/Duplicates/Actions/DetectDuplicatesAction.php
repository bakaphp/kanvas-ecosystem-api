<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\FindPeopleDuplicatesService;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
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

        foreach (new FindOrganizationDuplicatesService()->generate($this->app, $this->company) as $group) {
            $this->upsert(Organization::class, $group) ? $created++ : $skipped++;
        }

        foreach (new FindPeopleDuplicatesService()->generate($this->app, $this->company) as $group) {
            $this->upsert(People::class, $group) ? $created++ : $skipped++;
        }

        return ['created' => $created, 'skipped' => $skipped];
    }

    private function upsert(string $entityType, mixed $group): bool
    {
        $memberIds = $group->member_ids;
        sort($memberIds);
        $signature = sha1(implode(',', $memberIds));

        $existing = DuplicateReviewGroup::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('entity_type', $entityType)
            ->where('signature', $signature)
            ->exists();

        if ($existing) {
            return false;
        }

        DuplicateReviewGroup::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'entity_type' => $entityType,
            'canonical_id' => $group->canonical_id,
            'member_ids' => $memberIds,
            'signature' => $signature,
            'reason' => $group->reason,
            'status' => DuplicateReviewStatusEnum::PENDING->value,
        ]);

        return true;
    }
}
