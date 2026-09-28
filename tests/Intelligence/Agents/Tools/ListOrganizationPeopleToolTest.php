<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\ListOrganizationPeopleTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class ListOrganizationPeopleToolTest extends TestCase
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

    public function test_lists_org_people_with_scrubbed_custom_fields(): void
    {
        $org = $this->seedOrg('MctekkListUniqOrg');

        $person = $this->makePerson('Employeeuniq', 'Oneuniq');
        $this->linkToOrg($person, $org);
        $person->addEmail('emp@mctekk.test', 0, 0);
        $person->set('title', 'Software Engineer');
        $person->set('department', 'Engineering');
        $person->set('apollo_id', 'apl_internal_123');

        $result = new ListOrganizationPeopleTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke(organization_id: (int) $org->getId());

        $this->assertSame((int) $org->getId(), $result['organization']['organization_id']);
        $this->assertSame(1, $result['count']);
        $this->assertCount(1, $result['people']);

        $entry = $result['people'][0];
        $this->assertSame('Employeeuniq Oneuniq', $entry['name']);
        $this->assertSame('emp@mctekk.test', $entry['email']);
        $this->assertSame('Software Engineer', $entry['title']);

        // relevant business fields are surfaced, internal id fields are scrubbed
        $this->assertSame('Engineering', $entry['custom_fields']['department']);
        $this->assertArrayNotHasKey('apollo_id', $entry['custom_fields']);
    }

    public function test_resolves_by_name(): void
    {
        $org = $this->seedOrg('SolobyNameUniqCorp');
        $person = $this->makePerson('Nameresolveuniq', 'Personcc');
        $this->linkToOrg($person, $org);

        $result = new ListOrganizationPeopleTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke(organization_name: 'SolobyNameUniqCorp');

        $this->assertSame((int) $org->getId(), $result['organization']['organization_id']);
        $this->assertSame(1, $result['count']);
    }

    public function test_unknown_name_returns_error(): void
    {
        $result = new ListOrganizationPeopleTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke(organization_name: 'ZzzNoSuchOrgUniq');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_missing_arguments_returns_error(): void
    {
        $result = new ListOrganizationPeopleTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser)
            ->__invoke();

        $this->assertArrayHasKey('error', $result);
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

    private function linkToOrg(People $person, Organization $org): void
    {
        $person->organizations()->attach($org->getId(), ['created_at' => now()]);
    }

    private function makePerson(string $first, string $last): People
    {
        return People::factory()
            ->withAppId($this->currentApp->getId())
            ->withCompanyId($this->currentCompany->getId())
            ->withUserId($this->actingUser->getId())
            ->create(['firstname' => $first, 'lastname' => $last]);
    }
}
