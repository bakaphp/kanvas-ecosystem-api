<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Setup\Enums;

enum AccountingSetupStepStatusEnum: string
{
    case DONE = 'done';
    case SKIPPED = 'skipped';
    case FAILED = 'failed';
}
