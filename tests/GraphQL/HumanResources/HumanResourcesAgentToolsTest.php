<?php

declare(strict_types=1);

namespace Tests\GraphQL\HumanResources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Employees\Actions\CreateEmployeeAction;
use Kanvas\HumanResources\Employees\DataTransferObject\Employee as EmployeeData;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CreateEmployeeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\CreatePositionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\FindEmployeeTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\GetEmployeeLeaveBalanceTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\RequestLeaveTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\HumanResources\UpdateEmployeeTool;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use Tests\TestCase;

class HumanResourcesAgentToolsTest extends TestCase
{
    use DatabaseTransactions;
    use HrTestSetup;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    private function context(): array
    {
        $user = auth()->user();

        return [app(Apps::class), $user->getCurrentCompany(), $user];
    }

    private function selfEmployee(): Employee
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

    private function makeLeaveType(int $annualDays = 15): string
    {
        $name = 'Vacation ' . fake()->unique()->word();

        $this->graphQL('
            mutation($input: HrLeaveTypeInput!) { createHrLeaveType(input: $input) { id name } }
        ', ['input' => ['name' => $name, 'default_annual_days' => $annualDays, 'accrual_method' => 'ANNUAL_ALLOTMENT']])
            ->assertSuccessful();

        return $name;
    }

    public function testFindEmployeeResolvesByEmail(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();

        $result = new FindEmployeeTool()->withContext($app, $company, $user)->__invoke($user->email);

        $this->assertGreaterThanOrEqual(1, $result['count']);
        $this->assertContains($employee->getId(), array_column($result['employees'], 'employee_id'));
    }

    public function testLeaveBalanceAndRequestFlow(): void
    {
        $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $type = $this->makeLeaveType(15);

        $request = new RequestLeaveTool()->withContext($app, $company, $user)
            ->__invoke($type, '2026-03-02', '2026-03-04', employee_email: $user->email);

        $this->assertTrue($request['created']);
        $this->assertEquals(3, $request['days']);
        $this->assertEquals('pending', $request['status']);

        // Pass an explicit year so the period_year filter is exercised (regression: was querying 'year').
        $balance = new GetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(employee_email: $user->email, year: 2026);

        $this->assertNotEmpty($balance['balances']);
        $this->assertEquals(12, $balance['balances'][0]['available_days']);
        $this->assertEquals(2026, $balance['balances'][0]['year']);

        $blocked = new RequestLeaveTool()->withContext($app, $company, $user)
            ->__invoke($type, '2026-05-01', '2026-05-31', employee_email: $user->email);

        $this->assertFalse($blocked['created']);
        $this->assertStringContainsString('Not enough', $blocked['message']);
    }

    public function testGetLeaveBalanceUnknownEmployeeReturnsError(): void
    {
        [$app, $company, $user] = $this->context();

        $result = new GetEmployeeLeaveBalanceTool()->withContext($app, $company, $user)
            ->__invoke(employee_email: 'nobody-' . fake()->unique()->safeEmail());

        $this->assertEquals('error', $result['status']);
    }

    public function testCreatePositionToolIsAdminGatedAndIdempotent(): void
    {
        [$app, $company, $user] = $this->context();

        $created = new CreatePositionTool()->withContext($app, $company, $user)->__invoke('Jr Backend');
        $this->assertTrue($created['created']);
        $this->assertEquals('Jr Backend', $created['title']);

        // Same title again → returns the existing one, no duplicate.
        $again = new CreatePositionTool()->withContext($app, $company, $user)->__invoke('Jr Backend');
        $this->assertFalse($again['created']);
        $this->assertTrue($again['already_exists']);
        $this->assertEquals($created['position_id'], $again['position_id']);

        // Non-admin cannot create a position.
        $denied = new CreatePositionTool()->withContext($app, $company, Users::factory()->create())
            ->__invoke('Some Role');
        $this->assertFalse($denied['created']);
        $this->assertStringContainsString('administrator', $denied['message']);
    }

    public function testCreateEmployeeToolCreatesPeopleWhenMissing(): void
    {
        [$app, $company, $user] = $this->context();
        $newUser = $this->makeUser();
        $this->makePosition('QA Engineer');

        // The freshly-registered user has no People yet — the tool must resolve/create it.
        $result = new CreateEmployeeTool()->withContext($app, $company, $user)
            ->__invoke($newUser->email, 'QA Engineer', '2026-02-01');

        $this->assertTrue($result['created']);
        $this->assertNotNull($result['employee_id']);
    }

    public function testUpdateEmployeeChangesFieldsButNotUserOrPeople(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $this->makePosition('Staff Engineer');
        $originalUsersId = $employee->users_id;
        $originalPeopleId = $employee->people_id;

        $result = new UpdateEmployeeTool()->withContext($app, $company, $user)->__invoke(
            employee_id: $employee->getId(),
            description: 'Promoted to staff',
            status: 'active',
            position_title: 'Staff Engineer',
        );

        $this->assertTrue($result['updated']);
        $this->assertEquals('Promoted to staff', $result['description']);
        $this->assertEquals('active', $result['status']);
        $this->assertEquals('Staff Engineer', $result['position']);

        $fresh = Employee::query()->fromApp($app)->fromCompany($company)->where('id', $employee->getId())->first();
        $this->assertEquals($originalUsersId, $fresh->users_id);
        $this->assertEquals($originalPeopleId, $fresh->people_id);
    }

    public function testUpdateEmployeeProfileUpdatesPeopleNotLoginUser(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();
        $originalEmail = $employee->user->email;

        $result = new UpdateEmployeeTool()->withContext($app, $company, $user)->__invoke(
            employee_id: $employee->getId(),
            first_name: 'Soul',
            last_name: 'Four',
            birthday: '1990-05-01',
            phone: '+1-809-555-0142',
            address: '10 Calle Duarte',
            city: 'Santo Domingo',
        );

        $this->assertTrue($result['updated']);
        $this->assertEquals('Soul Four', $result['name']);

        $people = $employee->people->refresh();
        $this->assertEquals('Soul', $people->firstname);
        $this->assertEquals('Four', $people->lastname);
        $this->assertEquals('1990-05-01', $people->dob->format('Y-m-d'));
        // Phone is normalized to digits on save.
        $this->assertTrue($people->contacts()->where('contacts_types_id', 3)->where('value', '18095550142')->exists());
        $this->assertTrue($people->address()->where('address', '10 Calle Duarte')->where('city', 'Santo Domingo')->exists());

        // The login user account is untouched — email unchanged.
        $this->assertEquals($originalEmail, $employee->user->refresh()->email);
    }

    public function testUpdateEmployeeRejectsInvalidStatusAndNonAdmin(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $user] = $this->context();

        $bad = new UpdateEmployeeTool()->withContext($app, $company, $user)
            ->__invoke(employee_id: $employee->getId(), status: 'not-a-status');
        $this->assertFalse($bad['updated']);
        $this->assertStringContainsString('Invalid status', $bad['message']);

        $denied = new UpdateEmployeeTool()->withContext($app, $company, Users::factory()->create())
            ->__invoke(employee_id: $employee->getId(), description: 'x');
        $this->assertFalse($denied['updated']);
    }

    public function testRequestingHumanMustBeAdminEvenWhenAgentIs(): void
    {
        $employee = $this->selfEmployee();
        [$app, $company, $adminUser] = $this->context();

        // Agent context is an admin, but the HUMAN in the conversation is not → denied.
        $denied = new UpdateEmployeeTool()->withContext($app, $company, $adminUser)
            ->forRequestingUser(Users::factory()->create())
            ->__invoke(employee_id: $employee->getId(), description: 'nope');
        $this->assertFalse($denied['updated']);

        // Agent context is a non-admin, but the HUMAN is an admin → allowed.
        $ok = new UpdateEmployeeTool()->withContext($app, $company, Users::factory()->create())
            ->forRequestingUser($adminUser)
            ->__invoke(employee_id: $employee->getId(), description: 'ok');
        $this->assertTrue($ok['updated']);
    }

    public function testWriteToolsRejectNonAdmin(): void
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();
        // A bare factory user has no roles → isAdmin() is false.
        $nonAdmin = Users::factory()->create();
        $newUser = $this->makeUser();
        $this->makePosition('Analyst');

        $create = new CreateEmployeeTool()->withContext($app, $company, $nonAdmin)
            ->__invoke($newUser->email, 'Analyst', '2026-02-01');

        $this->assertFalse($create['created']);
        $this->assertStringContainsString('administrator', $create['message']);

        $request = new RequestLeaveTool()->withContext($app, $company, $nonAdmin)
            ->__invoke('Vacation', '2026-03-02', '2026-03-04', employee_email: $newUser->email);

        $this->assertFalse($request['created']);
        $this->assertStringContainsString('administrator', $request['message']);
    }

    public function testBulkCreateToolsBudgetRunsPerInputsNotPerToolName(): void
    {
        // Regression (Sentry KANVAS-ECOSYSTEM-621): NeuronAI caps a tool at 10 executions per turn keyed on
        // the tool NAME by default, so onboarding an org chart (11+ DISTINCT create_position/create_employee
        // calls) threw ToolRunsExceededException. HasRunKey keys the counter on the inputs instead: distinct
        // arguments → distinct keys (own budget), identical arguments → same key (loop still capped).
        $position = new CreatePositionTool();
        $this->assertInstanceOf(HasRunKey::class, $position);

        $keyCeo = $position->setInputs(['title' => 'CEO'])->getRunKey();
        $keyCto = $position->setInputs(['title' => 'CTO'])->getRunKey();
        $keyCeoAgain = $position->setInputs(['title' => 'CEO'])->getRunKey();

        $this->assertNotEquals($keyCeo, $keyCto, 'Distinct positions must not share a run budget.');
        $this->assertEquals($keyCeo, $keyCeoAgain, 'Identical calls must collapse to one key so a loop is capped.');

        $employee = new CreateEmployeeTool();
        $this->assertInstanceOf(HasRunKey::class, $employee);

        $keyOne = $employee->setInputs(['user_email' => 'a@x.com', 'position_title' => 'Dev'])->getRunKey();
        $keyTwo = $employee->setInputs(['user_email' => 'b@x.com', 'position_title' => 'Dev'])->getRunKey();
        $this->assertNotEquals($keyOne, $keyTwo, 'Distinct employees must not share a run budget.');
    }

    public function testCreateEmployeeToolOnboardsExistingUser(): void
    {
        [$app, $company, $user] = $this->context();
        $newUser = $this->makeUser();
        $this->makePosition('Designer');

        $result = new CreateEmployeeTool()->withContext($app, $company, $user)
            ->__invoke($newUser->email, 'Designer', '2026-02-01');

        $this->assertTrue($result['created']);
        $this->assertEquals('Designer', $result['position']);
        $this->assertNotNull($result['employee_id']);

        // Second onboarding of the same user is rejected by the one-employee-per-user guard.
        $again = new CreateEmployeeTool()->withContext($app, $company, $user)
            ->__invoke($newUser->email, 'Designer', '2026-02-01');

        $this->assertFalse($again['created']);
    }
}
