<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Actions;

use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;

/**
 * Shared by DetectDuplicatesAction (full sweep) and CheckPeopleDuplicateOnCreateAction (single
 * record) — inserts a group unless one with the same (entity_type, sorted member_ids) already
 * exists, regardless of its status.
 */
class UpsertDuplicateReviewGroupAction
{
    public function __construct(
        public readonly string $entityType,
        public readonly int $appsId,
        public readonly int $companiesId,
    ) {
    }

    public function execute(mixed $group): bool
    {
        $memberIds = $group->member_ids;
        sort($memberIds);
        $signature = sha1(implode(',', $memberIds));

        $exists = DuplicateReviewGroup::query()
            ->where('apps_id', $this->appsId)
            ->where('companies_id', $this->companiesId)
            ->where('entity_type', $this->entityType)
            ->where('signature', $signature)
            ->exists();

        if ($exists) {
            return false;
        }

        DuplicateReviewGroup::create([
            'apps_id' => $this->appsId,
            'companies_id' => $this->companiesId,
            'entity_type' => $this->entityType,
            'canonical_id' => $group->canonical_id,
            'member_ids' => $memberIds,
            'signature' => $signature,
            'reason' => $group->reason,
            'status' => DuplicateReviewStatusEnum::PENDING->value,
        ]);

        return true;
    }
}
