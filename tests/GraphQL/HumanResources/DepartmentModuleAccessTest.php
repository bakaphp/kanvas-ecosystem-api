<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Access\Actions\SetDepartmentModuleAccessAction;
use Kanvas\HumanResources\Access\DataTransferObject\DepartmentModuleAccess as AccessData;
use Kanvas\HumanResources\Access\Enums\AccessLevelEnum;
use Kanvas\HumanResources\Access\Models\DepartmentModuleAccess;
use Kanvas\HumanResources\Access\Services\DepartmentAccessResolver;
use Kanvas\HumanResources\Departments\Actions\CreateDepartmentAction;
use Kanvas\HumanResources\Departments\DataTransferObject\Department as DepartmentData;
use Kanvas\HumanResources\Departments\Models\Department;
use Tests\TestCase;

class DepartmentModuleAccessTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'hr', 'intelligence'];

    private function createDepartmentId(): string
    {
        return $this->graphQL('
            mutation($input: HrDepartmentInput!) { createHrDepartment(input: $input) { id } }
        ', ['input' => ['name' => 'Dept ' . fake()->unique()->word()]])
            ->assertSuccessful()
            ->json('data.createHrDepartment.id');
    }

    public function testSetDepartmentModuleAccess(): void
    {
        $deptId = $this->createDepartmentId();

        $this->graphQL('
            mutation($input: HrDepartmentModuleAccessInput!) {
                setHrDepartmentModuleAccess(input: $input) {
                    module_slug
                    level
                    department { id }
                }
            }
        ', ['input' => ['department_id' => $deptId, 'module_slug' => 'hr-leave', 'level' => 'VIEW']])
            ->assertSuccessful()
            ->assertJson(['data' => ['setHrDepartmentModuleAccess' => [
                'module_slug' => 'hr-leave',
                'level' => 'VIEW',
                'department' => ['id' => $deptId],
            ]]]);
    }

    public function testSetIsIdempotentUpsert(): void
    {
        $deptId = $this->createDepartmentId();
        $input = ['department_id' => $deptId, 'module_slug' => 'hr-leave', 'level' => 'VIEW'];

        $this->graphQL('mutation($input: HrDepartmentModuleAccessInput!){ setHrDepartmentModuleAccess(input:$input){ id } }', ['input' => $input])->assertSuccessful();

        $this->graphQL('
            mutation($input: HrDepartmentModuleAccessInput!) {
                setHrDepartmentModuleAccess(input: $input) { level }
            }
        ', ['input' => array_merge($input, ['level' => 'MANAGE'])])
            ->assertSuccessful()
            ->assertJson(['data' => ['setHrDepartmentModuleAccess' => ['level' => 'MANAGE']]]);

        // Upsert, not insert — exactly one row survives for this dept + module.
        $rows = DepartmentModuleAccess::query()
            ->where('department_id', (int) $deptId)
            ->where('module_slug', 'hr-leave')
            ->where('is_deleted', 0)
            ->get();

        $this->assertCount(1, $rows);
        $this->assertEquals('manage', $rows->first()->level);
    }

    public function testResolverInheritsFromParentDepartment(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $parent = new CreateDepartmentAction(
            new DepartmentData(app: $app, company: $company, user: $user, name: 'Parent ' . fake()->unique()->word()),
        )->execute();

        /** @var Department $child */
        $child = new CreateDepartmentAction(
            new DepartmentData(app: $app, company: $company, user: $user, name: 'Child ' . fake()->unique()->word(), parent: $parent),
        )->execute();

        new SetDepartmentModuleAccessAction(
            new AccessData(app: $app, company: $company, user: $user, department: $parent, moduleSlug: 'hr-leave', level: AccessLevelEnum::VIEW),
        )->execute();

        $map = new DepartmentAccessResolver()->forDepartment($child);

        $this->assertArrayHasKey('hr-leave', $map);
        $this->assertEquals(AccessLevelEnum::VIEW, $map['hr-leave']);
    }
}
