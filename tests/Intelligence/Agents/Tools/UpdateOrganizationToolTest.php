<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Intelligence\Agents\Neuron\Tools\CRM\UpdateOrganizationTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class UpdateOrganizationToolTest extends TestCase
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

    public function test_updates_only_provided_fields(): void
    {
        $org = $this->seedOrg('UpdateOrgUniq' . fake()->unique()->uuid(), [
            'email' => 'old@updateorg.test',
            'phone' => '18090000000',
            'address' => 'Old Address',
        ]);

        $result = $this->tool()->__invoke(
            organization_id: (int) $org->getId(),
            phone: '18099999999',
        );

        $this->assertArrayNotHasKey('error', $result);

        $org->refresh();
        $this->assertSame('18099999999', $org->phone);
        $this->assertSame('old@updateorg.test', $org->email);
        $this->assertSame('Old Address', $org->address);
    }

    public function test_renames_organization(): void
    {
        $org = $this->seedOrg('RenameOrgUniq' . fake()->unique()->uuid());
        $newName = 'RenamedOrgUniq' . fake()->unique()->uuid();

        $result = $this->tool()->__invoke(
            organization_id: (int) $org->getId(),
            name: $newName,
        );

        $this->assertArrayNotHasKey('error', $result);
        $this->assertSame($newName, $result['name']);

        $org->refresh();
        $this->assertSame($newName, $org->name);
    }

    public function test_unknown_id_returns_error(): void
    {
        $result = $this->tool()->__invoke(organization_id: 999999999, name: 'Whatever');

        $this->assertArrayHasKey('error', $result);
    }

    private function tool(): UpdateOrganizationTool
    {
        return new UpdateOrganizationTool()
            ->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function seedOrg(string $name, array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'apps_id' => $this->currentApp->getId(),
            'companies_id' => $this->currentCompany->getId(),
            'users_id' => $this->actingUser->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ], $attributes));
    }
}
