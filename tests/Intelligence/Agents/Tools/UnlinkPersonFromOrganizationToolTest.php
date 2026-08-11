<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Customers\Models\PeopleEmploymentHistory;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationPeople;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UnlinkPersonFromOrganizationTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class UnlinkPersonFromOrganizationToolTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->currentApp = app(Apps::class);
        $this->actingUser = static::$cachedUser;
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function test_unlinks_person_and_closes_employment(): void
    {
        $org = $this->seedOrg('UnlinkOrgUniq' . fake()->unique()->uuid());
        $person = $this->makePerson();
        OrganizationPeople::addPeopleToOrganization($org, $person);
        PeopleEmploymentHistory::create([
            'apps_id' => $this->currentApp->getId(),
            'peoples_id' => $person->getId(),
            'organizations_id' => $org->getId(),
            'position' => 'Manager',
            'status' => 1,
            'start_date' => now()->toDateString(),
        ]);

        $result = $this->tool()->__invoke(person_id: (int) $person->getId(), organization_id: (int) $org->getId());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame('Person unlinked from organization.', $result['message']);

        $this->assertFalse(
            $person->organizations()->where('organizations.id', $org->getId())->exists()
        );

        $employment = PeopleEmploymentHistory::query()
            ->where('peoples_id', $person->getId())
            ->where('organizations_id', $org->getId())
            ->firstOrFail();
        $this->assertSame(0, (int) $employment->status);
        $this->assertNotNull($employment->end_date);
    }

    public function test_only_removes_the_named_organization(): void
    {
        $orgA = $this->seedOrg('KeepOrgUniq' . fake()->unique()->uuid());
        $orgB = $this->seedOrg('DropOrgUniq' . fake()->unique()->uuid());
        $person = $this->makePerson();
        OrganizationPeople::addPeopleToOrganization($orgA, $person);
        OrganizationPeople::addPeopleToOrganization($orgB, $person);

        $this->tool()->__invoke(person_id: (int) $person->getId(), organization_id: (int) $orgB->getId());

        $this->assertTrue($person->organizations()->where('organizations.id', $orgA->getId())->exists());
        $this->assertFalse($person->organizations()->where('organizations.id', $orgB->getId())->exists());
    }

    public function test_not_linked_returns_nothing_to_remove(): void
    {
        $org = $this->seedOrg('UnrelatedOrgUniq' . fake()->unique()->uuid());
        $person = $this->makePerson();

        $result = $this->tool()->__invoke(person_id: (int) $person->getId(), organization_id: (int) $org->getId());

        $this->assertArrayNotHasKey('error', $result);
        $this->assertStringContainsString('nothing to remove', $result['message']);
    }

    public function test_unknown_person_returns_error(): void
    {
        $org = $this->seedOrg('NoPersonOrgUniq' . fake()->unique()->uuid());

        $result = $this->tool()->__invoke(person_id: 999999999, organization_id: (int) $org->getId());

        $this->assertArrayHasKey('error', $result);
    }

    private function tool(): UnlinkPersonFromOrganizationTool
    {
        return new UnlinkPersonFromOrganizationTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function seedOrg(string $name): Organization
    {
        return Organization::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }

    private function makePerson(): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => 'Unlink', 'lastname' => 'Tester']);
    }
}
