<?php

declare(strict_types=1);

namespace Tests\Guild\Leads;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Guild\Leads\Repositories\LeadsRepository;
use Tests\TestCase;

final class LeadsRepositoryNonClosedLeadsTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm'];

    public function testClosedStatusNamesDefaultsToTheTerminalTrio(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $this->assertSame(
            ['closed', 'sold', 'lost'],
            LeadsRepository::closedStatusNames($company)
        );
    }

    public function testClosedStatusNamesHonoursTheCompanyMapping(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $this->withClosedMapping($company, ['dead', 'junk'], function () use ($company): void {
            $this->assertSame(
                ['dead', 'junk'],
                LeadsRepository::closedStatusNames($company)
            );
        });
    }

    public function testClosedStatusNamesIgnoresAnEmptyOrMalformedMapping(): void
    {
        $company = auth()->user()->getCurrentCompany();

        $this->withClosedMapping($company, [], function () use ($company): void {
            $this->assertSame(
                ['closed', 'sold', 'lost'],
                LeadsRepository::closedStatusNames($company)
            );
        });
    }

    /**
     * The whole reason this exists alongside getPeopleActiveLeads(): Reynolds
     * dealers publish prospects as "Open", which the active whitelist
     * (['active', 'created']) rejects — so a live lead looks closed to a
     * whitelist and live to an exclusion.
     */
    public function testNonClosedLeadsIncludesAStatusTheActiveWhitelistWouldReject(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people, 'Open');

        $ids = LeadsRepository::getPeopleNonClosedLeads($people)->pluck('id')->all();

        $this->assertContains($lead->getId(), $ids);
        $this->assertNotContains(
            $lead->getId(),
            LeadsRepository::getPeopleActiveLeads($people)->pluck('id')->all(),
            'guard: if the active whitelist starts accepting "Open" this test no longer proves anything'
        );
    }

    public function testNonClosedLeadsExcludesATerminalStatus(): void
    {
        $people = $this->createPerson();
        $lead = $this->createLead($people, 'Sold');

        $this->assertNotContains(
            $lead->getId(),
            LeadsRepository::getPeopleNonClosedLeads($people)->pluck('id')->all()
        );
    }

    /**
     * The override has to swing both ways — a whitelist test only ever proves
     * the first half.
     */
    public function testMappingOverrideBothExcludesTheNewNameAndReadmitsTheDefaultOne(): void
    {
        $people = $this->createPerson();
        $deadLead = $this->createLead($people, 'Dead');
        $soldLead = $this->createLead($people, 'Sold');

        $company = auth()->user()->getCurrentCompany();

        $this->withClosedMapping($company, ['dead'], function () use ($people, $deadLead, $soldLead): void {
            $ids = LeadsRepository::getPeopleNonClosedLeads($people)->pluck('id')->all();

            $this->assertNotContains($deadLead->getId(), $ids, '"dead" is now terminal');
            $this->assertContains($soldLead->getId(), $ids, '"sold" is no longer terminal');
        });
    }

    private function withClosedMapping(Companies $company, array $closed, callable $assertions): void
    {
        $company->set(ConfigurationEnum::MAPPING_STATUS_CRM->value, ['closed' => $closed]);

        try {
            $assertions();
        } finally {
            $company->del(ConfigurationEnum::MAPPING_STATUS_CRM->value);
        }
    }

    private function createPerson(): People
    {
        $user = auth()->user();

        return People::factory()
            ->withUserId($user->getId())
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($user->getCurrentCompany()->getId())
            ->create();
    }

    private function createLead(People $people, string $statusName): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $statusId = DB::connection('crm')->table('leads_status')->insertGetId([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'name' => $statusName,
            'is_default' => 0,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create(['leads_status_id' => $statusId]);
    }
}
