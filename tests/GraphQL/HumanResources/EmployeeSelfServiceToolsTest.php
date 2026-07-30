<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\GetMyLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\RequestMyLeaveTool;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

class EmployeeSelfServiceToolsTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function seedSelfEmployeeAndLeaveType(): string
    {
        $user = auth()->user();

        new CreateEmployeeAction(
            new EmployeeData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition(),
                hiredAt: '2026-01-15',
            ),
        )->execute();

        $name = 'Vacation ' . fake()->unique()->word();
        $this->graphQL('
            mutation($input: HrLeaveTypeInput!) { createHrLeaveType(input: $input) { id } }
        ', ['input' => ['name' => $name, 'default_annual_days' => 15, 'accrual_method' => 'ANNUAL_ALLOTMENT']])
            ->assertSuccessful();

        return $name;
    }

    public function testRequestMyLeaveAndBalanceForCurrentEmployee(): void
    {
        $type = $this->seedSelfEmployeeAndLeaveType();
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $request = new RequestMyLeaveTool()->withContext($app, $company, $user)
            ->__invoke($type, '2026-09-01', '2026-09-07');

        $this->assertTrue($request['created']);
        $this->assertEquals(7, $request['days']);
        $this->assertEquals('pending', $request['status']);

        $balance = new GetMyLeaveBalanceTool()->withContext($app, $company, $user)->__invoke(year: 2026);

        $this->assertNotEmpty($balance['balances']);
        $this->assertEquals(8, $balance['balances'][0]['available_days']);
    }

    public function testSelfServiceToolsHandleNonEmployeeGracefully(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        $stranger = Users::factory()->create();

        $balance = new GetMyLeaveBalanceTool()->withContext($app, $company, $stranger)->__invoke();
        $this->assertEquals('not_employee', $balance['status']);

        $request = new RequestMyLeaveTool()->withContext($app, $company, $stranger)
            ->__invoke('Vacation', '2026-09-01', '2026-09-07');
        $this->assertFalse($request['created']);
    }
}
