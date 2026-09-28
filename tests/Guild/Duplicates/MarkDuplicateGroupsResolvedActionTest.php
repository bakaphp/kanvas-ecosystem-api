<?php

declare(strict_types=1);

namespace Tests\Guild\Duplicates;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Actions\MarkDuplicateGroupsResolvedAction;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Tests\TestCase;

class MarkDuplicateGroupsResolvedActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_marks_matching_pending_group_as_merged(): void
    {
        $group = $this->seedGroup([1657, 1682]);
        $unrelated = $this->seedGroup([1670, 1671]);

        $resolvedCount = new MarkDuplicateGroupsResolvedAction(
            entityType: People::class,
            appsId: $this->kanvasApp->getId(),
            companiesId: $this->company->getId(),
            sourceId: 1682,
            targetId: 1657,
            user: static::$cachedUser,
        )->execute();

        $this->assertSame(1, $resolvedCount);

        $group->refresh();
        $this->assertSame(DuplicateReviewStatusEnum::MERGED, $group->status);
        $this->assertSame(1657, $group->resolved_target_id);
        $this->assertSame(static::$cachedUser->getId(), $group->resolved_by_users_id);
        $this->assertNotNull($group->resolved_at);

        $unrelated->refresh();
        $this->assertSame(DuplicateReviewStatusEnum::PENDING, $unrelated->status, 'a group not touched by this merge stays pending.');
    }

    public function test_does_not_touch_an_already_resolved_group(): void
    {
        $group = $this->seedGroup([1657, 1682]);
        $group->status = DuplicateReviewStatusEnum::DISMISSED->value;
        $group->save();

        $resolvedCount = new MarkDuplicateGroupsResolvedAction(
            entityType: People::class,
            appsId: $this->kanvasApp->getId(),
            companiesId: $this->company->getId(),
            sourceId: 1682,
            targetId: 1657,
        )->execute();

        $this->assertSame(0, $resolvedCount);

        $group->refresh();
        $this->assertSame(DuplicateReviewStatusEnum::DISMISSED, $group->status);
    }

    private function seedGroup(array $memberIds): DuplicateReviewGroup
    {
        sort($memberIds);

        return DuplicateReviewGroup::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'entity_type' => People::class,
            'canonical_id' => $memberIds[0],
            'member_ids' => $memberIds,
            'signature' => sha1(implode(',', $memberIds)),
            'reason' => 'exact_name',
            'status' => DuplicateReviewStatusEnum::PENDING->value,
        ]);
    }
}
