<?php

declare(strict_types=1);

namespace Tests\Guild;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Models\CompaniesBranches;
use Kanvas\Guild\Customers\Factories\PeopleFactory;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Tests\TestCase;

final class RecalculateActiveLeadsCountCommandTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    private const COMMAND = 'kanvas-guild:recalculate-active-leads-count';

    public function testCompanyFilterRepairsPeopleInThatCompany(): void
    {
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1);
        $this->forceStaleCount($person, 99);

        $this->artisan(self::COMMAND, [
            '--companies_id' => $this->currentCompany()->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(0);

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testCompanyFilterLeavesPeopleFromOtherCompaniesUntouched(): void
    {
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1);
        $this->forceStaleCount($person, 99);

        $otherCompany = Companies::factory()->create();

        $this->artisan(self::COMMAND, [
            '--companies_id' => $otherCompany->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(0);

        $this->assertSame(99, $this->activeLeadsCount($person));
    }

    public function testBranchFilterRepairsPeopleWithALeadInThatBranch(): void
    {
        $branch = $this->createBranch();
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1, branchId: $branch->getId());
        $this->forceStaleCount($person, 99);

        $this->artisan(self::COMMAND, [
            '--companies_branches_id' => $branch->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(0);

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testBranchFilterLeavesPeopleWithoutALeadInThatBranchUntouched(): void
    {
        $branch = $this->createBranch();
        $otherBranch = $this->createBranch();
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1, branchId: $otherBranch->getId());
        $this->forceStaleCount($person, 99);

        $this->artisan(self::COMMAND, [
            '--companies_branches_id' => $branch->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(0);

        $this->assertSame(99, $this->activeLeadsCount($person));
    }

    public function testBranchFilterStillCountsOpenLeadsFromEveryBranch(): void
    {
        $branch = $this->createBranch();
        $otherBranch = $this->createBranch();
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1, branchId: $branch->getId());
        $this->createLead($person, leadsStatusId: 2, branchId: $otherBranch->getId());
        $this->forceStaleCount($person, 0);

        $this->artisan(self::COMMAND, [
            '--companies_branches_id' => $branch->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(0);

        $this->assertSame(2, $this->activeLeadsCount($person));
    }

    public function testBranchFilterSelectsPeopleWhoseOnlyBranchLeadWasSoftDeleted(): void
    {
        $branch = $this->createBranch();
        $person = $this->createPerson();
        $lead = $this->createLead($person, leadsStatusId: 1, branchId: $branch->getId());

        Lead::query()->where('id', $lead->getId())->update(['is_deleted' => 1]);
        $this->forceStaleCount($person, 99);

        $this->artisan(self::COMMAND, [
            '--companies_branches_id' => $branch->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(0);

        $this->assertSame(0, $this->activeLeadsCount($person));
    }

    public function testCompanyAndBranchOfTheSameCompanyCombine(): void
    {
        $branch = $this->createBranch();
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1, branchId: $branch->getId());
        $this->forceStaleCount($person, 99);

        $this->artisan(self::COMMAND, [
            '--companies_id' => $this->currentCompany()->getId(),
            '--companies_branches_id' => $branch->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(0);

        $this->assertSame(1, $this->activeLeadsCount($person));
    }

    public function testBranchFromAnotherCompanyFailsWithoutWriting(): void
    {
        $branch = $this->createBranch();
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1, branchId: $branch->getId());
        $this->forceStaleCount($person, 99);

        $otherCompany = Companies::factory()->create();

        $this->artisan(self::COMMAND, [
            '--companies_id' => $otherCompany->getId(),
            '--companies_branches_id' => $branch->getId(),
            '--peoples_id' => [$person->getId()],
        ])->assertExitCode(1);

        $this->assertSame(99, $this->activeLeadsCount($person));
    }

    public function testDryRunReportsWithoutWriting(): void
    {
        $person = $this->createPerson();
        $this->createLead($person, leadsStatusId: 1);
        $this->forceStaleCount($person, 99);

        $this->artisan(self::COMMAND, [
            '--companies_id' => $this->currentCompany()->getId(),
            '--peoples_id' => [$person->getId()],
            '--dry-run' => true,
        ])->assertExitCode(0);

        $this->assertSame(99, $this->activeLeadsCount($person));
    }

    private function currentCompany(): Companies
    {
        return auth()->user()->getCurrentCompany();
    }

    private function createBranch(): CompaniesBranches
    {
        return CompaniesBranches::factory()->create([
            'companies_id' => $this->currentCompany()->getId(),
            'users_id' => auth()->user()->getId(),
            'is_default' => 0,
        ]);
    }

    private function createPerson(): People
    {
        $app = app(Apps::class);
        $user = auth()->user();

        /** @var People $people */
        $people = PeopleFactory::new()
            ->withAppId($app->getId())
            ->withCompanyId($this->currentCompany()->getId())
            ->withUserId($user->getId())
            ->withContacts()
            ->create(['firstname' => 'ralc-' . uniqid('', true)]);

        return $people->fresh();
    }

    private function createLead(
        People $people,
        int $leadsStatusId,
        ?int $branchId = null,
        int $ownerId = 50
    ): Lead {
        $app = app(Apps::class);
        $user = auth()->user();

        return Lead::factory()
            ->withAppAndCompany($app->getId(), $this->currentCompany()->getId())
            ->withUserId($user->getId())
            ->withPeopleId($people->getId())
            ->create([
                'leads_owner_id' => $ownerId,
                'leads_status_id' => $leadsStatusId,
                'companies_branches_id' => $branchId ?? 0,
            ]);
    }

    /**
     * Query-builder update, so LeadObserver never sees it — that is the drift.
     */
    private function forceStaleCount(People $people, int $count): void
    {
        People::query()->where('id', $people->getId())->update(['active_leads_count' => $count]);
    }

    private function activeLeadsCount(People $people): int
    {
        return (int) People::query()->find($people->getId())->active_leads_count;
    }
}
