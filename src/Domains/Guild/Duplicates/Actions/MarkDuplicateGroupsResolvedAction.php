<?php

declare(strict_types=1);

namespace Kanvas\Guild\Duplicates\Actions;

use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Carbon;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;

/**
 * Called by Merge*Action right after a merge commits — closes the loop with the persisted review
 * queue (Fase 3) so a group a human/agent already resolved doesn't keep showing as pending. Any
 * pending group for this tenant/entity_type whose member_ids includes BOTH source and target is
 * considered resolved by this merge, even if the group originally had more than two members (a
 * later sweep re-flags whichever member is still an unresolved duplicate of the target).
 */
class MarkDuplicateGroupsResolvedAction
{
    public function __construct(
        public readonly string $entityType,
        public readonly int $appsId,
        public readonly int $companiesId,
        public readonly int $sourceId,
        public readonly int $targetId,
        public readonly ?UserInterface $user = null,
    ) {
    }

    public function execute(): int
    {
        $groups = DuplicateReviewGroup::query()
            ->where('apps_id', $this->appsId)
            ->where('companies_id', $this->companiesId)
            ->where('entity_type', $this->entityType)
            ->where('status', DuplicateReviewStatusEnum::PENDING->value)
            ->get()
            ->filter(fn (DuplicateReviewGroup $group) => in_array($this->sourceId, $group->member_ids, true)
                && in_array($this->targetId, $group->member_ids, true));

        foreach ($groups as $group) {
            $group->status = DuplicateReviewStatusEnum::MERGED->value;
            $group->resolved_by_users_id = $this->user?->getId();
            $group->resolved_at = Carbon::now();
            $group->resolved_target_id = $this->targetId;
            $group->save();
        }

        return $groups->count();
    }
}
