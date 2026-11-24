<?php

declare(strict_types=1);

namespace Tests\Guild\Customers;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Services\PeopleDuplicateQueueService;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Tests\TestCase;

final class PeopleDuplicateQueueServiceTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        // The People observer queues its own duplicate check; groups here are written by hand.
        Queue::fake();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_pending_returns_a_group_whose_members_still_match(): void
    {
        $lastname = 'Orozco' . uniqid();
        $a = $this->seedPeople('Ismael', $lastname);
        $b = $this->seedPeople('ismael', strtolower($lastname));
        $group = $this->queueGroup([$a, $b]);

        $pending = $this->service()->pending();

        $this->assertContains($group->getId(), $this->ids($pending));
        $this->assertSame(DuplicateReviewStatusEnum::PENDING, $group->fresh()->status);
    }

    public function test_a_renamed_pair_is_expired_instead_of_reported(): void
    {
        $lastname = 'Placeholder' . uniqid();
        $a = $this->seedPeople('Lead', $lastname);
        $b = $this->seedPeople('Lead', $lastname);
        $group = $this->queueGroup([$a, $b]);

        $b->update(['firstname' => 'Andres', 'lastname' => 'Capdepon' . uniqid()]);

        $pending = $this->service()->pending();

        $this->assertNotContains($group->getId(), $this->ids($pending));
        $this->assertSame(DuplicateReviewStatusEnum::EXPIRED, $group->fresh()->status);
    }

    public function test_a_group_drops_the_member_that_no_longer_matches(): void
    {
        $lastname = 'Pina' . uniqid();
        $a = $this->seedPeople('Andres', $lastname);
        $b = $this->seedPeople('Andres', $lastname);
        $c = $this->seedPeople('Andres', $lastname);
        $group = $this->queueGroup([$a, $b, $c]);

        $c->update(['lastname' => 'Other' . uniqid()]);

        $this->assertContains($group->getId(), $this->ids($this->service()->pending()));

        $group->refresh();
        $this->assertSame([(int) $a->getId(), (int) $b->getId()], array_map('intval', $group->member_ids));
        $this->assertSame(DuplicateReviewGroup::signatureFor([$a->getId(), $b->getId()]), $group->signature);
    }

    public function test_a_shrunk_group_already_dismissed_stays_dismissed(): void
    {
        $lastname = 'Abreu' . uniqid();
        $a = $this->seedPeople('Alan', $lastname);
        $b = $this->seedPeople('Alan', $lastname);
        $c = $this->seedPeople('Alan', $lastname);
        $dismissed = $this->queueGroup([$a, $b], DuplicateReviewStatusEnum::DISMISSED);
        $group = $this->queueGroup([$a, $b, $c]);

        $c->update(['lastname' => 'Other' . uniqid()]);

        $this->assertNotContains($group->getId(), $this->ids($this->service()->pending()));
        $this->assertSame(DuplicateReviewStatusEnum::EXPIRED, $group->fresh()->status);
        $this->assertSame(DuplicateReviewStatusEnum::DISMISSED, $dismissed->fresh()->status);
    }

    public function test_a_group_whose_canonical_was_deleted_is_expired(): void
    {
        $lastname = 'Deleted' . uniqid();
        $a = $this->seedPeople('Maria', $lastname);
        $b = $this->seedPeople('Maria', $lastname);
        $group = $this->queueGroup([$a, $b]);

        $a->update(['is_deleted' => 1]);

        $this->assertNotContains($group->getId(), $this->ids($this->service()->pending()));
        $this->assertSame(DuplicateReviewStatusEnum::EXPIRED, $group->fresh()->status);
    }

    public function test_pending_filters_by_reason_and_respects_the_limit(): void
    {
        $lastname = 'Reason' . uniqid();
        $a = $this->seedPeople('Pedro', $lastname);
        $b = $this->seedPeople('Pedro', $lastname);
        $c = $this->seedPeople('Pedro', $lastname);
        $exactName = $this->queueGroup([$a, $b]);
        $this->queueGroup([$a, $b, $c]);

        $byReason = $this->service()->pending(reason: 'email_match');
        $this->assertNotContains($exactName->getId(), $this->ids($byReason));

        $this->assertCount(1, $this->service()->pending(maxGroups: 1));
    }

    public function test_detect_persists_the_groups_it_finds(): void
    {
        $lastname = 'Detect' . uniqid();
        $a = $this->seedPeople('Lucia', $lastname);
        $b = $this->seedPeople('Lucia', $lastname);

        $this->service()->detect();

        $this->assertTrue(
            DuplicateReviewGroup::query()
                ->where('companies_id', $this->company->getId())
                ->where('signature', DuplicateReviewGroup::signatureFor([$a->getId(), $b->getId()]))
                ->where('status', DuplicateReviewStatusEnum::PENDING->value)
                ->exists()
        );
    }

    private function service(): PeopleDuplicateQueueService
    {
        return new PeopleDuplicateQueueService($this->kanvasApp, $this->company);
    }

    /**
     * @param list<People> $members
     */
    private function queueGroup(array $members, DuplicateReviewStatusEnum $status = DuplicateReviewStatusEnum::PENDING): DuplicateReviewGroup
    {
        $ids = array_map(fn (People $people): int => (int) $people->getId(), $members);
        sort($ids);

        return DuplicateReviewGroup::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'entity_type' => People::class,
            'canonical_id' => $ids[0],
            'member_ids' => $ids,
            'signature' => DuplicateReviewGroup::signatureFor($ids),
            'reason' => 'exact_name',
            'status' => $status->value,
        ]);
    }

    /**
     * @param list<DuplicateReviewGroup> $groups
     * @return list<int>
     */
    private function ids(array $groups): array
    {
        return array_map(fn (DuplicateReviewGroup $group): int => (int) $group->getId(), $groups);
    }

    private function seedPeople(string $firstname, string $lastname): People
    {
        return People::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'firstname' => $firstname,
            'lastname' => $lastname,
            'name' => $firstname . ' ' . $lastname,
        ]);
    }
}
