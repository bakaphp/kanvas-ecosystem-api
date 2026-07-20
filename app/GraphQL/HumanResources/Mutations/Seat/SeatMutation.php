<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Seat;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Departments\Models\Department;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Seats\Actions\AssignSeatAction;
use Kanvas\HumanResources\Seats\Actions\EndSeatAction;
use Kanvas\HumanResources\Seats\DataTransferObject\SeatAssignment as SeatAssignmentData;
use Kanvas\HumanResources\Seats\Models\SeatAssignment;

class SeatMutation
{
    use ResolvesActingContext;

    public function assign(mixed $rootValue, array $request): SeatAssignment
    {
        $context = $this->actingContext();
        $input = $request['input'];

        /** @var Employee $employee */
        $employee = Employee::getByIdFromCompanyApp((int) $input['employee_id'], $context->company, $context->app);
        /** @var Department $department */
        $department = Department::getByIdFromCompanyApp((int) $input['department_id'], $context->company, $context->app);

        return new AssignSeatAction(
            new SeatAssignmentData(
                app: $context->app,
                company: $context->company,
                user: $context->user,
                employee: $employee,
                department: $department,
                allocationPct: isset($input['allocation_pct']) ? (int) $input['allocation_pct'] : 100,
                isPrimary: $input['is_primary'] ?? true,
                effectiveFrom: $input['effective_from'] ?? null,
            ),
        )->execute();
    }

    public function end(mixed $rootValue, array $request): SeatAssignment
    {
        $context = $this->actingContext();

        /** @var SeatAssignment $seat */
        $seat = SeatAssignment::getByIdFromCompanyApp((int) $request['id'], $context->company, $context->app);

        return new EndSeatAction($seat, $request['effective_to'] ?? null)->execute();
    }
}
