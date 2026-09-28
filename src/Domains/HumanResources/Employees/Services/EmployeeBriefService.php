<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Kanvas\HumanResources\Employees\Models\Employee;

/**
 * Where a person sits in the org chart, rendered the same way everywhere an agent needs it —
 * a structured brief for tool payloads and a one-line render for the system prompt. Keeping
 * both here stops the prompt and the tools from describing the same teammate differently.
 */
class EmployeeBriefService
{
    /**
     * Every render walks position, department and the manager's own people/user rows. Without this
     * both entry points cost five lazy queries per employee, on the agent's per-turn hot path.
     */
    private const array RELATIONS = [
        'position',
        'department',
        'parent.people',
        'parent.user',
    ];

    /**
     * @return array<string, mixed>|null Null when the user is not an employee of this company.
     */
    public function forUser(
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): ?array {
        $employee = new EmployeeIdentityResolver()->fromUser($user, $company, $app);

        return $employee === null ? null : $this->brief($employee);
    }

    public function renderTextForUser(
        UserInterface $user,
        CompanyInterface $company,
        AppInterface $app
    ): ?string {
        $employee = new EmployeeIdentityResolver()->fromUser($user, $company, $app);

        return $employee === null ? null : $this->renderText($employee);
    }

    /**
     * @return array<string, mixed>
     */
    public function brief(Employee $employee): array
    {
        $employee->loadMissing(self::RELATIONS);

        return array_filter([
            'employee_id' => $employee->getId(),
            'employee_number' => Str::trimToNull($employee->employee_number),
            'position' => Str::trimToNull($employee->position?->title),
            'position_level' => Str::trimToNull($employee->position?->level),
            'department' => Str::trimToNull($employee->department?->name),
            'reports_to' => $this->managerName($employee),
            'employment_type' => Str::trimToNull($employee->employment_type),
            'status' => Str::trimToNull($employee->status),
            'about' => Str::trimToNull($employee->description),
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * One line for the system prompt, e.g.
     * "Head of Sales (Sales) — reports to Ana Perez · full_time · active".
     */
    public function renderText(Employee $employee): string
    {
        $employee->loadMissing(self::RELATIONS);

        $department = Str::trimToNull($employee->department?->name);
        $role = Str::trimToNull($employee->position?->title) ?? 'no position on record';

        if ($department !== null) {
            $role .= ' (' . $department . ')';
        }

        $manager = $this->managerName($employee);

        $parts = array_filter([
            $role,
            $manager !== null ? 'reports to ' . $manager : null,
            Str::trimToNull($employee->employment_type),
            Str::trimToNull($employee->status),
            Str::trimToNull($employee->description),
        ], fn (?string $value): bool => $value !== null);

        return implode(' · ', $parts);
    }

    private function managerName(Employee $employee): ?string
    {
        $manager = $employee->parent;
        if (! $manager instanceof Employee) {
            return null;
        }

        $name = Str::trimToNull($manager->people?->name)
            ?? Str::trimToNull((string) $manager->user?->firstname . ' ' . (string) $manager->user?->lastname);

        return $name ?? 'employee #' . $manager->getId();
    }
}
