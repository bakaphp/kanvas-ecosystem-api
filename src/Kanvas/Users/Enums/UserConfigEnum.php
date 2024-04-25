<?php

declare(strict_types=1);

namespace Kanvas\Users\Enums;

enum UserConfigEnum: string
{
    case USER_INTERACTIONS = 'user-interactions';
    case TWO_FACTOR_AUTH_30_DAYS = 'two_fact_validate_in_thirty';
}
