<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Departments\Actions\CreateDepartmentAction;
use Kanvas\HumanResources\Departments\DataTransferObject\Department as DepartmentData;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Intelligence\Agents\Models\Agent;
use Kanvas\Intelligence\Agents\Neuron\SystemUserAgent;
use Kanvas\Intelligence\Agents\Neuron\Tools\System\WhoIsUserTool;
use Kanvas\Users\Models\Users;
use Tests\GraphQL\HumanResources\HrTestSetup;
use Tests\TestCase;

class SystemUserAgentHrContextTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    public function testInstructionsDescribeTheHumanWithTheirOrgChartSeat(): void
    {
        $human = $this->makeUser();
        $employee = $this->makeEmployee($human);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(),
            entity: $human,
            user: $human,
        );

        $instructions = $handler->instructions();

        $this->assertStringContainsString("## Who you're talking to", $instructions);
        $this->assertStringContainsString((string) $human->email, $instructions);
        $this->assertStringContainsString((string) $employee->position->title, $instructions);
        $this->assertStringContainsString((string) $employee->department->name, $instructions);
    }

    public function testInstructionsSayTheRoleIsUnknownWhenThereIsNoEmployeeRecord(): void
    {
        $human = $this->makeUser();

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent(),
            entity: $human,
            user: $human,
        );

        $instructions = $handler->instructions();

        $this->assertStringContainsString("## Who you're talking to", $instructions);
        $this->assertStringContainsString('no HR employee record', $instructions);
    }

    public function testInstructionsInjectTheAgentsOwnOrgChartSeat(): void
    {
        $agentUser = $this->makeUser();
        $employee = $this->makeEmployee($agentUser);

        $handler = new SystemUserAgent();
        $handler->setConfiguration(
            agent: $this->makeAgent($agentUser),
            entity: $agentUser,
            user: $agentUser,
        );

        $instructions = $handler->instructions();

        $this->assertStringContainsString(
            sprintf(
                'Your seat on the org chart: %s (%s)',
                $employee->position->title,
                $employee->department->name,
            ),
            $instructions,
        );
        $this->assertStringNotContainsString(
            "## Who you're talking to",
            $instructions,
            'The agent must not be described as the person it is talking to when the turn actor IS its own user',
        );
    }

    public function testWhoIsUserToolReturnsTheOrgChartSeat(): void
    {
        $human = $this->makeUser();
        $employee = $this->makeEmployee($human);

        $result = new WhoIsUserTool(
            $this->hrApp(),
            $this->hrCompany(),
            $human
        )();

        $this->assertSame($employee->position->title, $result['hr']['position']);
        $this->assertSame($employee->department->name, $result['hr']['department']);
    }

    public function testWhoIsUserToolFlagsAMissingEmployeeRecord(): void
    {
        $result = new WhoIsUserTool(
            $this->hrApp(),
            $this->hrCompany(),
            $this->makeUser()
        )();

        $this->assertNull($result['hr']);
        $this->assertStringContainsString('no HR employee record', $result['hr_note']);
    }

    private function makeAgent(?Users $user = null): Agent
    {
        $factory = Agent::factory()
            ->withAppId(app(Apps::class)->getId())
            ->withCompanyId($this->hrCompany()->getId());

        return $user === null
            ? $factory->create()
            : $factory->create(['user_id' => $user->getId()]);
    }

    private function makeEmployee(Users $user): Employee
    {
        $department = new CreateDepartmentAction(
            new DepartmentData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                user: auth()->user(),
                name: 'Revenue ' . fake()->unique()->uuid(),
            ),
        )->execute();

        return new CreateEmployeeAction(
            new EmployeeData(
                app: $this->hrApp(),
                company: $this->hrCompany(),
                loginUser: $user,
                people: $this->makePeople($user),
                position: $this->makePosition('Head of Sales ' . fake()->unique()->uuid()),
                hiredAt: '2026-01-15',
                department: $department,
            ),
        )->execute();
    }
}
