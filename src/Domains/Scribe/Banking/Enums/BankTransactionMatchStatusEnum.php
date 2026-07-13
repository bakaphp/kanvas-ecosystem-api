<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

enum BankTransactionMatchStatusEnum: string
{
    case UNMATCHED = 'unmatched';
    case AUTO_MATCHED = 'auto_matched';
    case MANUALLY_MATCHED = 'manually_matched';
    case IGNORED = 'ignored';

    /**
     * A settled txn reuses the settling document's journal entry, so the bank composer must never
     * post a second one for it. This is the guard behind the no-double-posting invariant.
     */
    public function isSettled(): bool
    {
        return $this === self::AUTO_MATCHED || $this === self::MANUALLY_MATCHED;
    }
}
