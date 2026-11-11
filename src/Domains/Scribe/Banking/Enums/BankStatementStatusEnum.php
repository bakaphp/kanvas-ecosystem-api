<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

enum BankStatementStatusEnum: string
{
    case POSTED = 'posted';
    case REJECTED = 'rejected';
}
