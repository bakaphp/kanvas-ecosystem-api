<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

enum ContactTypeEnum: int
{
    case EMAIL = 1;
    case PHONE = 2;
    case CELLPHONE = 3;
}
