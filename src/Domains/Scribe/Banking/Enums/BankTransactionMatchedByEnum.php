<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

enum BankTransactionMatchedByEnum: string
{
    case SYSTEM = 'system';
    case AGENT = 'agent';
    case HUMAN = 'human';
}
