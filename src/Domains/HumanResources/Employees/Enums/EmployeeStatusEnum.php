<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Enums;

enum EmployeeStatusEnum: string
{
    case ONBOARDING = 'onboarding';
    case ACTIVE = 'active';
    case ON_LEAVE = 'on_leave';
    case SUSPENDED = 'suspended';
    case DEPARTED = 'departed';
}
