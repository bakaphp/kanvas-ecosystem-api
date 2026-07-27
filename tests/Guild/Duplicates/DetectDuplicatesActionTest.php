<?php

declare(strict_types=1);

namespace Tests\Guild\Duplicates;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Duplicates\Actions\DetectDuplicatesAction;
use Kanvas\Guild\Duplicates\Enums\DuplicateReviewStatusEnum;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

class DetectDuplicatesActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm', 'ecosystem'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function test_queues_pending_groups_for_both_entity_types(): void
    {
        $clusterName = 'AcmeCluster' . uniqid();
        $orgA = $this->seedOrganization($clusterName);
        $orgB = $this->seedOrganization(strtolower($clusterName));

        $lastname = 'Pina' . uniqid();
        $peopleA = $this->seedPeople('Andres', $lastname);
        $peopleB = $this->seedPeople('andres', strtolower($lastname));

        $result = new DetectDuplicatesAction($this->kanvasApp, $this->company)->execute();

        $this->assertGreaterThanOrEqual(2, $result['created']);

        $orgGroup = DuplicateReviewGroup::query()
            ->where('entity_type', Organization::class)
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->get()
            ->first(fn ($g) => in_array((int) $orgA->id, $g->member_ids, true));

        $this->assertNotNull($orgGroup);
        $this->assertEqualsCanonicalizing([(int) $orgA->id, (int) $orgB->id], $orgGroup->member_ids);
        $this->assertSame(DuplicateReviewStatusEnum::PENDING, $orgGroup->status);

        $peopleGroup = DuplicateReviewGroup::query()
            ->where('entity_type', People::class)
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->get()
            ->first(fn ($g) => in_array((int) $peopleA->id, $g->member_ids, true));

        $this->assertNotNull($peopleGroup);
        $this->assertEqualsCanonicalizing([(int) $peopleA->id, (int) $peopleB->id], $peopleGroup->member_ids);
    }

    public function test_running_twice_does_not_duplicate_rows(): void
    {
        $clusterName = 'DoubleRun' . uniqid();
        $this->seedOrganization($clusterName);
        $this->seedOrganization(strtolower($clusterName));

        $first = new DetectDuplicatesAction($this->kanvasApp, $this->company)->execute();
        $this->assertGreaterThanOrEqual(1, $first['created']);

        $second = new DetectDuplicatesAction($this->kanvasApp, $this->company)->execute();

        $this->assertSame(0, $second['created'], 'second sweep should skip everything already queued.');
        $this->assertGreaterThanOrEqual(1, $second['skipped']);
    }

    public function test_a_dismissed_groups_status_survives_a_re_sweep(): void
    {
        $clusterName = 'Dismissed' . uniqid();
        $this->seedOrganization($clusterName);
        $this->seedOrganization(strtolower($clusterName));

        new DetectDuplicatesAction($this->kanvasApp, $this->company)->execute();

        $group = DuplicateReviewGroup::query()
            ->where('entity_type', Organization::class)
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->latest('id')
            ->first();

        $group->status = DuplicateReviewStatusEnum::DISMISSED->value;
        $group->save();

        new DetectDuplicatesAction($this->kanvasApp, $this->company)->execute();

        $group->refresh();
        $this->assertSame(
            DuplicateReviewStatusEnum::DISMISSED,
            $group->status,
            'a dismissed decision must not be overwritten by a later sweep re-detecting the same cluster.',
        );
    }

    private function seedOrganization(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
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
