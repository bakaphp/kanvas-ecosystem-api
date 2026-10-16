<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\GetOrganizationTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetOrganizationAddressTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\SetOrganizationCustomFieldsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\TagOrganizationTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class OrganizationProfileToolsTest extends TestCase
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

    public function test_set_custom_fields_from_a_json_string_and_read_them_back(): void
    {
        $org = $this->seedOrg();

        $result = $this->tool(new SetOrganizationCustomFieldsTool())->__invoke(
            organization_id: (int) $org->getId(),
            custom_fields: '{"industry": "Banking", "employees": 250}',
        );
        $this->assertTrue($result['success']);

        $profile = $this->tool(new GetOrganizationTool())->__invoke(organization_id: (int) $org->getId());

        $this->assertSame('Banking', $profile['custom_fields']['industry']);
        $this->assertSame(250, $profile['custom_fields']['employees']);
    }

    public function test_set_custom_fields_rejects_unparseable_json(): void
    {
        $org = $this->seedOrg();

        $result = $this->tool(new SetOrganizationCustomFieldsTool())->__invoke(
            organization_id: (int) $org->getId(),
            custom_fields: 'industry = banking',
        );

        $this->assertArrayHasKey('error', $result);
    }

    public function test_tag_organization_adds_a_tag(): void
    {
        // Add only: a same-test remove deadlocks across the social/crm connections, see PeopleToolsTest.
        $org = $this->seedOrg();

        $result = $this->tool(new TagOrganizationTool())->__invoke(
            organization_id: (int) $org->getId(),
            tags: ['key-account-' . uniqid()],
        );

        $this->assertSame((int) $org->getId(), $result['organization_id']);
        $this->assertSame('Tags added.', $result['message']);
    }

    public function test_tag_organization_unknown_id_returns_error(): void
    {
        $result = $this->tool(new TagOrganizationTool())->__invoke(organization_id: 999999999, tags: ['vip']);

        $this->assertArrayHasKey('error', $result);
    }

    public function test_set_address_patches_without_blanking_other_fields(): void
    {
        $org = $this->seedOrg();

        $this->tool(new SetOrganizationAddressTool())->__invoke(
            organization_id: (int) $org->getId(),
            type: 'Headquarters',
            address: 'Av. Abraham Lincoln 100',
            city: 'Santo Domingo',
            zip: '10101',
        );

        $patched = $this->tool(new SetOrganizationAddressTool())->__invoke(
            organization_id: (int) $org->getId(),
            type: 'Headquarters',
            zip: '10148',
        );

        $this->assertSame('Address updated.', $patched['message']);
        $this->assertSame('Av. Abraham Lincoln 100', $patched['address']['address']);
        $this->assertSame('Santo Domingo', $patched['address']['city']);
        $this->assertSame('10148', $patched['address']['zip']);
        $this->assertSame(1, $org->addresses()->count());

        $profile = $this->tool(new GetOrganizationTool())->__invoke(organization_id: (int) $org->getId());
        $this->assertSame('Headquarters', $profile['addresses'][0]['type']);
    }

    public function test_set_address_rejects_unknown_type(): void
    {
        $org = $this->seedOrg();

        $result = $this->tool(new SetOrganizationAddressTool())->__invoke(
            organization_id: (int) $org->getId(),
            type: 'Moon Base',
            address: 'Crater 1',
        );

        $this->assertArrayHasKey('error', $result);
        $this->assertSame(0, $org->addresses()->count());
    }

    private function tool(object $tool): object
    {
        return $tool->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    private function seedOrg(): Organization
    {
        return Organization::create([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => 'OrgProfileUniq' . uniqid(),
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
