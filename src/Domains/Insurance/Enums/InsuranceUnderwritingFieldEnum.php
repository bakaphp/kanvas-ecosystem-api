<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Enums;

enum InsuranceUnderwritingFieldEnum: string
{
    case IP_ADDRESS = 'ip_address';
    case BIRTH_DATE = 'birth_date';
    case AGE = 'age';
    case MARITAL_STATUS = 'marital_status';
    case SEX = 'sex';
    case USAGE = 'usage';
    case CIRCULATION_ZONE = 'circulation_zone';
}
