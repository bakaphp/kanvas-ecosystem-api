<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Traits;

use Kanvas\Exceptions\ModelNotFoundException;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\Users\Models\Users;

/**
 * Resolve an HR Employee for a tool __invoke by email or id, scoped to the tool's
 * app + company (from HasKanvasContext), returning either the Employee OR a structured
 * error array the LLM can act on — so a hallucinated email/id never crashes the chat.
 *
 *   $result = $this->resolveEmployeeOrError($email, $id);
 *   if (is_array($result)) { return $result; }
 *   $employee = $result;
 */
trait ResolvesEmployeeForTool
{
    /**
     * @return Employee|array{status: string, message: string}
     */
    protected function resolveEmployeeOrError(?string $email = null, ?int $employeeId = null): Employee|array
    {
        if ($employeeId !== null) {
            $employee = Employee::query()
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->notDeleted()
                ->where('id', $employeeId)
                ->first();

            if ($employee instanceof Employee) {
                return $employee;
            }
        }

        if ($email !== null && $email !== '') {
            try {
                $employee = new EmployeeIdentityResolver()->fromUser(Users::getByEmail($email), $this->company, $this->app);

                if ($employee instanceof Employee) {
                    return $employee;
                }
            } catch (ModelNotFoundException) {
                // fall through to the not-found response
            }
        }

        return [
            'status' => 'error',
            'message' => 'No employee found for the given reference. Use find_employee to look up the person by '
                . 'name or email first, then retry with their email or employee id. Do not invent an id.',
        ];
    }
}
