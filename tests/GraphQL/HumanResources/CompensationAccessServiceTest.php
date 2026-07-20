<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\HumanResources\Compensation\Services\CompensationAccessService;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class CompensationAccessServiceTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function makeEmployeeForCurrentUser()
    {
        $user = auth()->user();

        return new CreateEmployeeAction(
            new EmployeeData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();
    }

    public function testOwnerCanViewOwnCompensation(): void
    {
        $employee = $this->makeEmployeeForCurrentUser();

        $this->assertTrue(new CompensationAccessService()->canViewCompensation(auth()->user(), $employee));
    }

    public function testNonPrivilegedUserCannotViewOthersCompensation(): void
    {
        $employee = $this->makeEmployeeForCurrentUser();
        // A user with no roles is genuinely non-admin (RegisterUsersAction would make them a company owner).
        $stranger = Users::factory()->create();

        $this->assertFalse(new CompensationAccessService()->canViewCompensation($stranger, $employee));
    }
}
