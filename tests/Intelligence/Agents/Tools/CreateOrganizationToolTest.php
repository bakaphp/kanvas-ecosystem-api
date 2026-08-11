<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\CreateOrganizationTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class CreateOrganizationToolTest extends TestCase
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

    public function test_creates_a_new_organization(): void
    {
        $name = 'CreateOrgUniq' . fake()->unique()->uuid();

        $result = $this->tool()->__invoke(
            name: $name,
            email: 'ops@createorg.test',
            phone: '18095551234',
            address: '123 Main St',
            state: 'Santo Domingo',
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertTrue($result['created']);
        $this->assertSame($name, $result['name']);

        $org = Organization::getByIdFromCompanyApp((int) $result['organization_id'], $this->currentCompany, $this->currentApp);
        $this->assertSame('ops@createorg.test', $org->email);
        $this->assertSame('18095551234', $org->phone);
        $this->assertSame('123 Main St', $org->address);
        $this->assertSame('Santo Domingo', $org->state);
    }

    public function test_dedups_on_name(): void
    {
        $name = 'DedupOrgUniq' . fake()->unique()->uuid();
        $existing = $this->seedOrg($name);

        $result = $this->tool()->__invoke(name: $name);

        $this->assertFalse($result['created']);
        $this->assertSame((int) $existing->getId(), (int) $result['organization_id']);
    }

    public function test_blank_name_returns_error(): void
    {
        $result = $this->tool()->__invoke(name: '   ');

        $this->assertArrayHasKey('error', $result);
    }

    public function test_unknown_organization_type_returns_error(): void
    {
        $result = $this->tool()->__invoke(
            name: 'TypeErrOrgUniq' . fake()->unique()->uuid(),
            organization_type_id: 999999999,
        );

        $this->assertArrayHasKey('error', $result);
    }

    private function tool(): CreateOrganizationTool
    {
        return new CreateOrganizationTool()
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
}
