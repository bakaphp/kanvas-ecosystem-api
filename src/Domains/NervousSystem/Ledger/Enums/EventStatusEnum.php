<?php

declare(strict_types=1);

namespace Kanvas\NervousSystem\Ledger\Enums;

enum EventStatusEnum: string
{
    case INFO = 'info';
    case SUCCESS = 'success';
    case ERROR = 'error';
}
