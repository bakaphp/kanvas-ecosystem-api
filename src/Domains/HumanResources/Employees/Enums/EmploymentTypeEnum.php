<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Employees\Enums;

enum EmploymentTypeEnum: string
{
    case EMPLOYEE = 'employee';
    case CONTRACTOR = 'contractor';
    case SHARED = 'shared';
}
