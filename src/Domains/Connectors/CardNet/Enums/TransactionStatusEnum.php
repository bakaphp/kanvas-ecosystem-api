<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Enums;

enum TransactionStatusEnum: int
{
    case APPROVED = 1;
    case PENDING = 2;
    case PREAUTHORIZED = 3;
    case REJECTED = 4;
}
