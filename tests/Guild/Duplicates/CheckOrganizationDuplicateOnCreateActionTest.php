<?php

declare(strict_types=1);

namespace Tests\Guild\Duplicates;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Duplicates\Actions\CheckOrganizationDuplicateOnCreateAction;
use Kanvas\Guild\Duplicates\Jobs\CheckOrganizationDuplicateJob;
use Kanvas\Guild\Duplicates\Models\DuplicateReviewGroup;
use Kanvas\Guild\Organizations\Models\Organization;
use Tests\TestCase;

class CheckOrganizationDuplicateOnCreateActionTest extends TestCase
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

    public function test_queues_a_pending_group_when_the_new_record_collides(): void
    {
        Queue::fake();

        $cluster = 'AcmeQueue' . uniqid();
        $existing = $this->seedOrganization($cluster);
        $newOrganization = $this->seedOrganization(strtolower($cluster));

        $created = new CheckOrganizationDuplicateOnCreateAction($newOrganization)->execute();

        $this->assertSame(1, $created);

        $group = DuplicateReviewGroup::query()
            ->where('apps_id', $this->kanvasApp->getId())
            ->where('companies_id', $this->company->getId())
            ->where('entity_type', Organization::class)
            ->first();

        $this->assertNotNull($group);
        $this->assertEqualsCanonicalizing([(int) $existing->id, (int) $newOrganization->id], $group->member_ids);
    }

    public function test_does_nothing_when_the_new_record_has_no_match(): void
    {
        $lonely = $this->seedOrganization('Lonely Vendor ' . uniqid());

        $created = new CheckOrganizationDuplicateOnCreateAction($lonely)->execute();

        $this->assertSame(0, $created);
    }

    public function test_creating_an_organization_dispatches_the_check_job(): void
    {
        Queue::fake();

        $organization = $this->seedOrganization('Dispatch Test ' . uniqid());

        Queue::assertPushed(CheckOrganizationDuplicateJob::class, fn ($job) => $job->organizationId === $organization->getId());
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
}
