<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Enums;

/**
 * Anti-loop guard for outbound sync.
 *
 * - EXTERNAL: row originated outside Kanvas (e.g. ADM Cloud webhook ingest). Outbound listeners NO-OP on these
 *   to prevent push-pull loops.
 * - KANVAS: row originated inside Kanvas (native CRUD Action). Outbound listeners push these back to the configured
 *   accounting connector.
 */
enum JournalEntryOriginEnum: string
{
    case EXTERNAL = 'external';
    case KANVAS = 'kanvas';
}
