<?php

declare(strict_types=1);

namespace Tests\GraphQL\Ecosystem\Roles;

use Kanvas\AccessControlList\Actions\CreateAbilitiesByModule;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Enums\AppEnums;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class RoleAbilitiesTest extends TestCase
{
    protected Apps $apps;
    protected array $appKeyHeader;

    public function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->appKeyHeader = [
            AppEnums::KANVAS_APP_KEY_HEADER->getValue() => $this->apps->keys()->first()->client_secret_id,
        ];

        // Ensure abilities and abilities_modules are seeded for this app
        new CreateAbilitiesByModule($this->apps)->execute();
    }

    public function testGetRoleAbilitiesReturnsModulesWithAbilities(): void
    {
        $role = $this->createTestRoleWithPermissions([
            [
                'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                'permission' => ['view', 'create', 'edit', 'delete'],
                'module_permissions' => [],
            ],
        ]);

        $response = $this->graphQL(
            query: '
                query getRoleAbilities($role: String!) {
                    getRoleAbilities(role: $role)
                }
            ',
            variables: ['role' => $role['name']],
            headers: $this->appKeyHeader
        );

        $response->assertSuccessful();

        $abilities = $response->json('data.getRoleAbilities');
        $this->assertNotEmpty($abilities);
        $this->assertArrayHasKey('CRM', $abilities);

        $crmModule = $abilities['CRM'];
        $this->assertEquals('CRM', $crmModule['name']);
        $this->assertNotEmpty($crmModule['systemModules']);

        // Find Lead in system modules
        $leadModule = collect($crmModule['systemModules'])
            ->firstWhere('name', 'Kanvas\\Guild\\Leads\\Models\\Lead');

        $this->assertNotNull($leadModule, 'Lead should appear in CRM system modules');
        $this->assertCount(4, $leadModule['abilities']);

        // All should have roleId = true
        foreach ($leadModule['abilities'] as $ability) {
            $this->assertTrue(
                $ability['roleId'],
                "Ability '{$ability['title']}' should have roleId=true"
            );
        }
    }

    public function testUpdateRoleRemovesPermission(): void
    {
        $role = $this->createTestRoleWithPermissions([
            [
                'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                'permission' => ['view', 'create', 'edit', 'delete'],
                'module_permissions' => [],
            ],
        ]);

        // Verify all 4 permissions are granted
        $abilities = $this->getRoleAbilities($role['name']);
        $leadModule = $this->findSystemModule($abilities, 'CRM', 'Kanvas\\Guild\\Leads\\Models\\Lead');
        $this->assertCount(4, $leadModule['abilities']);

        // Update role: remove 'edit' from Lead
        $this->graphQL(
            query: '
                mutation updateRole($id: ID!, $input: RoleInput) {
                    updateRole(id: $id, input: $input) {
                        name
                    }
                }
            ',
            variables: [
                'id' => $role['id'],
                'input' => [
                    'name' => $role['name'],
                    'permissions' => [
                        [
                            'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                            'permission' => ['view', 'create', 'delete'],
                            'module_permissions' => [],
                        ],
                    ],
                ],
            ],
            headers: $this->appKeyHeader
        )->assertSuccessful();

        // Verify edit is now false
        $abilities = $this->getRoleAbilities($role['name']);
        $leadModule = $this->findSystemModule($abilities, 'CRM', 'Kanvas\\Guild\\Leads\\Models\\Lead');

        $abilitiesMap = collect($leadModule['abilities'])->keyBy('title');

        // Find the edit ability (title could be "Edit" or "Edit leads")
        $editAbility = $abilitiesMap->first(
            fn ($a) => str_starts_with(strtolower($a['title']), 'edit')
        );
        $this->assertNotNull($editAbility, 'Edit ability should still appear in the list');
        $this->assertFalse($editAbility['roleId'], 'Edit should be revoked (roleId=false)');

        // View, create, delete should still be true
        $grantedCount = $abilitiesMap->filter(fn ($a) => $a['roleId'] === true)->count();
        $this->assertEquals(3, $grantedCount, 'Should have exactly 3 granted permissions');
    }

    public function testUpdateRoleAddsPermission(): void
    {
        // Start with only view+create
        $role = $this->createTestRoleWithPermissions([
            [
                'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                'permission' => ['view', 'create'],
                'module_permissions' => [],
            ],
        ]);

        // Update: add edit+delete
        $this->graphQL(
            query: '
                mutation updateRole($id: ID!, $input: RoleInput) {
                    updateRole(id: $id, input: $input) {
                        name
                    }
                }
            ',
            variables: [
                'id' => $role['id'],
                'input' => [
                    'name' => $role['name'],
                    'permissions' => [
                        [
                            'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                            'permission' => ['view', 'create', 'edit', 'delete'],
                            'module_permissions' => [],
                        ],
                    ],
                ],
            ],
            headers: $this->appKeyHeader
        )->assertSuccessful();

        $abilities = $this->getRoleAbilities($role['name']);
        $leadModule = $this->findSystemModule($abilities, 'CRM', 'Kanvas\\Guild\\Leads\\Models\\Lead');

        $grantedCount = collect($leadModule['abilities'])
            ->filter(fn ($a) => $a['roleId'] === true)
            ->count();
        $this->assertEquals(4, $grantedCount, 'All 4 permissions should be granted');
    }

    public function testGetRoleAbilitiesNoDuplicates(): void
    {
        $role = $this->createTestRoleWithPermissions([
            [
                'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                'permission' => ['view', 'create', 'edit', 'delete'],
                'module_permissions' => [],
            ],
        ]);

        // Update the role twice to ensure no duplicates accumulate
        for ($i = 0; $i < 2; $i++) {
            $this->graphQL(
                query: '
                    mutation updateRole($id: ID!, $input: RoleInput) {
                        updateRole(id: $id, input: $input) {
                            name
                        }
                    }
                ',
                variables: [
                    'id' => $role['id'],
                    'input' => [
                        'name' => $role['name'],
                        'permissions' => [
                            [
                                'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                                'permission' => ['view', 'create', 'edit', 'delete'],
                                'module_permissions' => [],
                            ],
                        ],
                    ],
                ],
                headers: $this->appKeyHeader
            )->assertSuccessful();
        }

        $abilities = $this->getRoleAbilities($role['name']);
        $leadModule = $this->findSystemModule($abilities, 'CRM', 'Kanvas\\Guild\\Leads\\Models\\Lead');

        // Should have exactly 4 abilities, not 8 from duplicates
        $this->assertCount(4, $leadModule['abilities'], 'Should not have duplicate abilities');
    }

    public function testGetRoleAbilitiesMultipleModules(): void
    {
        $role = $this->createTestRoleWithPermissions([
            [
                'model_name' => 'Kanvas\\Guild\\Leads\\Models\\Lead',
                'permission' => ['view', 'create', 'edit', 'delete'],
                'module_permissions' => [],
            ],
            [
                'model_name' => 'Kanvas\\Guild\\Customers\\Models\\People',
                'permission' => ['view', 'create'],
                'module_permissions' => [],
            ],
            [
                'model_name' => 'Kanvas\\Guild\\Pipelines\\Models\\Pipeline',
                'permission' => ['view'],
                'module_permissions' => [],
            ],
        ]);

        $abilities = $this->getRoleAbilities($role['name']);
        $this->assertArrayHasKey('CRM', $abilities);

        $crmModules = collect($abilities['CRM']['systemModules']);

        // Lead: 4 granted
        $lead = $crmModules->firstWhere('name', 'Kanvas\\Guild\\Leads\\Models\\Lead');
        $this->assertNotNull($lead);
        $leadGranted = collect($lead['abilities'])->filter(fn ($a) => $a['roleId'])->count();
        $this->assertEquals(4, $leadGranted);

        // People: 2 granted
        $people = $crmModules->firstWhere('name', 'Kanvas\\Guild\\Customers\\Models\\People');
        $this->assertNotNull($people);
        $peopleGranted = collect($people['abilities'])->filter(fn ($a) => $a['roleId'])->count();
        $this->assertEquals(2, $peopleGranted);

        // Pipeline: 1 granted
        $pipeline = $crmModules->firstWhere('name', 'Kanvas\\Guild\\Pipelines\\Models\\Pipeline');
        $this->assertNotNull($pipeline);
        $pipelineGranted = collect($pipeline['abilities'])->filter(fn ($a) => $a['roleId'])->count();
        $this->assertEquals(1, $pipelineGranted);
    }

    public function testUpdateRoleWithModulePermissions(): void
    {
        $role = $this->createTestRoleWithPermissions([
            [
                'model_name' => 'Kanvas\\Guild\\Rotations\\Models\\Rotation',
                'permission' => ['view', 'create', 'edit', 'delete'],
                'module_permissions' => ['view', 'manage'],
            ],
        ]);

        $abilities = $this->getRoleAbilities($role['name']);
        $crmModules = collect($abilities['CRM']['systemModules']);

        $rotation = $crmModules->firstWhere('name', 'Kanvas\\Guild\\Rotations\\Models\\Rotation');
        $this->assertNotNull($rotation);

        $grantedCount = collect($rotation['abilities'])->filter(fn ($a) => $a['roleId'])->count();
        $this->assertEquals(4, $grantedCount);
    }

    private function createTestRoleWithPermissions(array $permissions): array
    {
        $user = auth()->user();
        $user->assign(RolesEnums::OWNER->value);

        $input = [
            'name' => 'TestRole_' . fake()->unique()->word() . '_' . time(),
            'title' => 'Test Role',
            'permissions' => $permissions,
        ];

        $response = $this->graphQL(
            query: '
                mutation createRole($input: RoleInput!) {
                    createRole(input: $input) {
                        id
                        name
                    }
                }
            ',
            variables: ['input' => $input],
            headers: $this->appKeyHeader
        );

        $response->assertSuccessful();

        return $response->json('data.createRole');
    }

    private function getRoleAbilities(string $roleName): array
    {
        $response = $this->graphQL(
            query: '
                query getRoleAbilities($role: String!) {
                    getRoleAbilities(role: $role)
                }
            ',
            variables: ['role' => $roleName],
            headers: $this->appKeyHeader
        );

        $response->assertSuccessful();

        return $response->json('data.getRoleAbilities');
    }

    private function findSystemModule(array $abilities, string $module, string $modelName): ?array
    {
        $this->assertArrayHasKey($module, $abilities, "Module '{$module}' should exist");

        $systemModule = collect($abilities[$module]['systemModules'])
            ->firstWhere('name', $modelName);

        $this->assertNotNull(
            $systemModule,
            "System module '{$modelName}' should exist in '{$module}'"
        );

        return $systemModule;
    }
}
