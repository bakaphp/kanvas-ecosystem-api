<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Enums;

enum ReadinessStatusEnum: string
{
    case OK = 'ok';
    /** Works, with a catch the admin should know about. */
    case WARNING = 'warning';
    /** Calls fail until it is fixed. */
    case ERROR = 'error';
}
