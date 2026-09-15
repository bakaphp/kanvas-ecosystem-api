<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Enums;

enum ConsentSignalEnum: string
{
    case STOP = 'stop';
    case START = 'start';
    case HELP = 'help';
}
