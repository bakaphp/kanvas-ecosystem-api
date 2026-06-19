<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Enums;

enum JournalEntryStatusEnum: string
{
    case DRAFT = 'draft';
    case POSTED = 'posted';
    case REVERSED = 'reversed';
}
