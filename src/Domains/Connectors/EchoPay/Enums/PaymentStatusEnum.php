<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum PaymentStatusEnum: string
{
    case AUTHENTICATION_SUCCESSFUL = 'AUTHENTICATION_SUCCESSFUL';
}
