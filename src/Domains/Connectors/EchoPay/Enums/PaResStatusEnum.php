<?php

declare(strict_types=1);

namespace Kanvas\Connectors\EchoPay\Enums;

enum PaResStatusEnum: string
{
    case AUTHENTICATED = 'Y';
    case ATTEMPTED = 'A';
    case UNAVAILABLE = 'U';
    case NOT_AUTHENTICATED = 'N';
    case REJECTED = 'R';
    case CHALLENGE_REQUIRED = 'C';

    public function isFailure(): bool
    {
        return in_array($this, [self::NOT_AUTHENTICATED, self::REJECTED], strict: true);
    }
}
