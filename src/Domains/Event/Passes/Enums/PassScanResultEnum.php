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

    public function isRejection(): bool
    {
        return $this !== self::ADMITTED && $this !== self::ALREADY_ADMITTED;
    }
}
