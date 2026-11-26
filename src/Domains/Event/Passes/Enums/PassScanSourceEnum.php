<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\Enums;

enum PassScanSourceEnum: string
{
    case QR = 'qr';
    case MANUAL = 'manual';
}
