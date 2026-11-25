<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Enums;

enum InviteCodeStatusEnum: string
{
    case AVAILABLE = 'available';
    case REDEEMED = 'redeemed';
    case REVOKED = 'revoked';
}
