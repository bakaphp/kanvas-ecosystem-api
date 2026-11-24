<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Banking\Enums;

enum BankStatementIngestOutcomeEnum: string
{
    case POSTED = 'posted';

    /** The exact same rows for the same account were already posted. Nothing new landed. */
    case DUPLICATE = 'duplicate';

    /** A rule failed (balances, account, rows). Nothing landed; the reason says what to ask for. */
    case REJECTED = 'rejected';

    /** A CSV whose header no mapper knows. The header comes back so a mapping can be proposed. */
    case UNKNOWN_LAYOUT = 'unknown_layout';
}
