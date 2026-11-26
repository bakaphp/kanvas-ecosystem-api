<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Enums;

enum PassScanResultEnum: string
{
    case ADMITTED = 'admitted';
    case ALREADY_ADMITTED = 'already_admitted';
    case INVALID_CODE = 'invalid_code';
    case NOT_ACTIVE = 'not_active';
    case WRONG_RESOURCE = 'wrong_resource';
    case OUTSIDE_WINDOW = 'outside_window';
    case FORBIDDEN = 'forbidden';
    case NOT_AUTHORIZED = 'not_authorized';

    public function isRejection(): bool
    {
        return ! in_array($this, [self::ADMITTED, self::ALREADY_ADMITTED, self::NOT_AUTHORIZED], true);
    }
}
