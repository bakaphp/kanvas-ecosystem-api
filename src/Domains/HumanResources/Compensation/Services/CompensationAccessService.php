<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Compensation\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\HumanResources\Employees\Services\EmployeeIdentityResolver;
use Kanvas\Users\Models\Users;

/**
 * Decides whether a viewer may see an employee's compensation, matching the industry norm:
 * own → always; admin / compensation.view.all → all; view.department / view.reports → scoped;
 * everyone else → no. Pay-band ranges are a broader, separate tier.
 */
class CompensationAccessService
{
    public function canViewCompensation(Users $viewer, Employee $target): bool
    {
        if ($target->users_id === $viewer->getId()) {
            return true;
        }

        if ($viewer->isAdmin() || $viewer->can('compensation.view.all')) {
            return true;
        }

        if ($viewer->can('compensation.view.department') && $this->sameDepartment($viewer, $target)) {
            return true;
        }

        if ($viewer->can('compensation.view.reports') && $this->managesTarget($viewer, $target)) {
            return true;
        }

        return false;
    }

    public function canViewBand(Users $viewer): bool
    {
        return $viewer->isAdmin() || $viewer->can('compensation.band.view');
    }

    private function viewerEmployee(Users $viewer): ?Employee
    {
        return new EmployeeIdentityResolver()->fromUser($viewer, $viewer->getCurrentCompany(), app(Apps::class));
    }

    private function managesTarget(Users $viewer, Employee $target): bool
    {
        $viewerEmployee = $this->viewerEmployee($viewer);

        return $viewerEmployee !== null
            && $viewerEmployee->descendants()->where('id', $target->getId())->exists();
    }

    private function sameDepartment(Users $viewer, Employee $target): bool
    {
        $viewerEmployee = $this->viewerEmployee($viewer);

        return $viewerEmployee !== null
            && $viewerEmployee->department_id !== null
            && $viewerEmployee->department_id === $target->department_id;
    }
}
