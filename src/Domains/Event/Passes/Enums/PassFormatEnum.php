<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Enums;

enum PassFormatEnum: string
{
    case NUMERIC_PIN = 'numeric';
    case QR_CODE = 'qr';
}
