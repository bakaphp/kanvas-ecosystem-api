<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\HumanResources\Employees\Models\Employee;
use Kanvas\Social\Channels\Actions\CreateChannelAction;
use Kanvas\Social\Channels\DataTransferObject\Channel as ChannelDto;
use Kanvas\Social\Channels\Models\Channel;
use Kanvas\Users\Models\Users;

/**
 * Durable per-employee activity channel — one stable channel ("employee-channel-{id}")
 * that accumulates every HR note and interaction for the UI timeline. Mirrors
 * PeopleChannelService.
 */
class EmployeeChannelService
{
    private const string SLUG_PREFIX = 'employee-channel-';

    public function findOrCreateForEmployee(
        Employee $employee,
        Apps $app,
        Companies $company,
        ?Users $owner = null,
    ): Channel {
        $dto = new ChannelDto(
            apps: $app,
            companies: $company,
            users: $this->resolveOwner($employee, $company, $owner),
            entity_id: $employee->getId(),
            entity_namespace: Employee::class,
            name: 'Activity - ' . $this->label($employee),
            description: 'Per-employee HR activity timeline.',
            slug: self::SLUG_PREFIX . $employee->getId(),
        );

        return new CreateChannelAction($dto)->execute();
    }

    private function label(Employee $employee): string
    {
        $name = trim((string) ($employee->people?->getName() ?? ''));

        return $name !== '' ? $name : ('Employee #' . $employee->getId());
    }

    private function resolveOwner(Employee $employee, Companies $company, ?Users $owner): Users
    {
        if ($owner !== null) {
            return $owner;
        }

        $aiAgentUser = $company->getAiAgentUser();
        if ($aiAgentUser !== null) {
            return $aiAgentUser;
        }

        return Users::getById($employee->users_id);
    }
}
