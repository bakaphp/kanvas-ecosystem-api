<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeChannelService;
use Tests\TestCase;

class EmployeeChannelTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function makeEmployee(): Employee
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

    public function testEmployeeGetsAStableActivityChannel(): void
    {
        $employee = $this->makeEmployee();
        $company = auth()->user()->getCurrentCompany();

        $channel = new EmployeeChannelService()->findOrCreateForEmployee($employee, $this->hrApp(), $company);

        $this->assertEquals('employee-channel-' . $employee->getId(), $channel->slug);
        $this->assertEquals((string) $employee->getId(), (string) $channel->entity_id);
    }

    public function testChannelIsIdempotentPerEmployee(): void
    {
        $employee = $this->makeEmployee();
        $company = auth()->user()->getCurrentCompany();
        $service = new EmployeeChannelService();

        $first = $service->findOrCreateForEmployee($employee, $this->hrApp(), $company);
        $second = $service->findOrCreateForEmployee($employee, $this->hrApp(), $company);

        $this->assertEquals($first->getId(), $second->getId());
    }

    public function testActivityChannelAccumulatesLifecycleTimelineWithoutLeakingSalary(): void
    {
        $employee = $this->makeEmployee();
        $company = auth()->user()->getCurrentCompany();

        $typeId = $this->graphQL('
            mutation($input: HrLeaveTypeInput!) { createHrLeaveType(input: $input) { id } }
        ', ['input' => ['name' => 'Vacation ' . fake()->unique()->word(), 'default_annual_days' => 15, 'accrual_method' => 'ANNUAL_ALLOTMENT']])
            ->assertSuccessful()
            ->json('data.createHrLeaveType.id');

        $requestId = $this->graphQL('
            mutation($input: HrLeaveRequestInput!) { requestHrLeave(input: $input) { id } }
        ', ['input' => ['leave_type_id' => $typeId, 'start_date' => '2026-03-02', 'end_date' => '2026-03-04']])
            ->assertSuccessful()
            ->json('data.requestHrLeave.id');

        $this->graphQL('
            mutation($id: ID!) { decideHrLeaveRequest(id: $id, decision: APPROVE) { status } }
        ', ['id' => $requestId])->assertSuccessful();

        $this->graphQL('
            mutation($input: HrCompensationInput!) { recordHrCompensation(input: $input) { id } }
        ', ['input' => ['employee_id' => (string) $employee->getId(), 'amount' => 222333, 'effective_from' => '2026-02-01']])
            ->assertSuccessful();

        $channel = new EmployeeChannelService()->findOrCreateForEmployee($employee, $this->hrApp(), $company);
        $timeline = $channel->messages()->pluck('message')->map(fn ($m) => (string) $m)->all();
        $blob = json_encode($timeline);

        $this->assertStringContainsString('onboarded', strtolower($blob));
        $this->assertStringContainsString('Requested 3 day(s)', $blob);
        $this->assertStringContainsString('Leave request approved', $blob);
        $this->assertStringContainsString('Compensation updated', $blob);
        // The timeline records THAT comp changed, never the salary figure itself.
        $this->assertStringNotContainsString('222333', $blob);
    }
}
