<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Enums;

enum ReadinessStatusEnum: string
{
    case READY = 'ready';
    case MISSING = 'missing';
    case DEGRADED = 'degraded';
}
