<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Services;

use Baka\Contracts\CompanyInterface;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\DataTransferObject\PeopleDuplicateGroup;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Actions\UpsertDuplicateReviewGroupAction;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;

class PeopleDuplicateQueueService
{
    private readonly FindPeopleDuplicatesService $finder;

    public function __construct(
        private readonly Apps $app,
        private readonly CompanyInterface $company,
    ) {
        $this->finder = new FindPeopleDuplicatesService();
    }

    /**
     * @return list<DuplicateReviewGroup>
     */
    public function pending(int $maxGroups = 100, ?string $reason = null): array
    {
        $query = DuplicateReviewGroup::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('entity_type', People::class)
            ->where('status', DuplicateReviewStatusEnum::PENDING->value)
            ->where('is_deleted', false)
            ->when($reason !== null, fn ($query) => $query->where('reason', $reason));

        $groups = [];
        foreach ($query->lazyByIdDesc(100) as $group) {
            if (count($groups) >= $maxGroups) {
                break;
            }

            if ($this->revalidate($group)) {
                $groups[] = $group;
            }
        }

        return $groups;
    }

    /**
     * @return list<PeopleDuplicateGroup>
     */
    public function detect(int $maxGroups = 100): array
    {
        $groups = $this->finder->generate(
            app: $this->app,
            company: $this->company,
            maxGroups: $maxGroups,
        );

        $upserter = new UpsertDuplicateReviewGroupAction(People::class, $this->app->getId(), $this->company->getId());
        foreach ($groups as $group) {
            $upserter->execute($group);
        }

        return $groups;
    }

    /**
     * A group is written once, when a member is created or a sweep runs, and nothing re-checks it
     * after: a rename, a contact edit or a delete leaves members that no longer match. Shrinks the
     * group to the members that still match its canonical, or expires it when fewer than two remain
     * or the shrunk set is already a row of its own (possibly one a human dismissed).
     */
    private function revalidate(DuplicateReviewGroup $group): bool
    {
        $memberIds = array_map('intval', $group->member_ids);
        sort($memberIds);

        $surviving = array_values(array_intersect($memberIds, $this->idsStillMatching($group->canonical_id)));

        if ($surviving === $memberIds) {
            return true;
        }

        $signature = DuplicateReviewGroup::signatureFor($surviving);

        if (count($surviving) < 2 || $this->signatureTaken($signature, $group)) {
            $group->status = DuplicateReviewStatusEnum::EXPIRED->value;
            $group->resolved_at = Carbon::now();
            $group->save();

            return false;
        }

        $group->member_ids = $surviving;
        $group->canonical_id = $surviving[0];
        $group->signature = $signature;
        $group->save();

        return true;
    }

    /**
     * @return list<int>
     */
    private function idsStillMatching(int $canonicalId): array
    {
        /** @var People|null $canonical */
        $canonical = People::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->find($canonicalId);

        if ($canonical === null) {
            return [];
        }

        $ids = [$canonical->getId()];
        foreach ($this->finder->checkRecord($canonical) as $match) {
            $ids = [...$ids, ...$match->member_ids];
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function signatureTaken(string $signature, DuplicateReviewGroup $group): bool
    {
        return DuplicateReviewGroup::query()
            ->withSignature(
                $group->apps_id,
                $group->companies_id,
                $group->entity_type,
                $signature
            )
            ->where('id', '!=', $group->getId())
            ->exists();
    }
}
