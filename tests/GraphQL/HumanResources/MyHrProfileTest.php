<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Tests\TestCase;

class MyHrProfileTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence'];

    public function testMyHrProfileNullWhenNotAnEmployee(): void
    {
        $this->graphQL('
            query { myHrProfile { id } }
        ')
            ->assertSuccessful()
            ->assertJson(['data' => ['myHrProfile' => null]]);
    }

    public function testMyHrProfileReturnsOwnEmployee(): void
    {
        $user = auth()->user();

        $employee = new CreateEmployeeAction(
            new EmployeeData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();

        $this->graphQL('
            query { myHrProfile { id } }
        ')
            ->assertSuccessful()
            ->assertJson(['data' => ['myHrProfile' => ['id' => (string) $employee->getId()]]]);
    }
}
