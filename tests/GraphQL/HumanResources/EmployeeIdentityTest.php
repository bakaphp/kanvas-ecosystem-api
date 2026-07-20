<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Tests\TestCase;

class EmployeeIdentityTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence'];

    public function testResolveEmployeeFromUser(): void
    {
        $app = $this->hrApp();
        $user = auth()->user();
        $company = $this->hrCompany();

        $employee = new CreateEmployeeAction(
            new EmployeeData(
                app: $app,
                company: $company,
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();

        $resolved = new EmployeeIdentityResolver()->fromUser($user, $company, $app);

        $this->assertNotNull($resolved);
        $this->assertEquals($employee->getId(), $resolved->getId());
    }

    public function testUserLinkedToOneEmployeeOnly(): void
    {
        $app = $this->hrApp();
        $user = auth()->user();
        $company = $this->hrCompany();

        new CreateEmployeeAction(
            new EmployeeData(
                app: $app,
                company: $company,
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();

        $this->expectExceptionMessage('already linked');

        new CreateEmployeeAction(
            new EmployeeData(
                app: $app,
                company: $company,
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();
    }
}
