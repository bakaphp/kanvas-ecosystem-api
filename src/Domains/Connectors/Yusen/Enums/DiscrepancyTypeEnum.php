<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Yusen\Enums;

enum DiscrepancyTypeEnum: string
{
    case QUANTITY_MISMATCH = 'QUANTITY_MISMATCH';
    case MISSING_IN_KANVAS = 'MISSING_IN_KANVAS';
    case MISSING_IN_NETSUITE = 'MISSING_IN_NETSUITE';
    case MISSING_IN_YUSEN = 'MISSING_IN_YUSEN';
    // Fallback for a source added later that has no dedicated MISSING_IN_* of its own.
    case MISSING_IN_SOURCE = 'MISSING_IN_SOURCE';

    /**
     * What to call an item Yusen sent that the given source has never heard of.
     */
    public static function missingFor(string $sourceKey): self
    {
        return match ($sourceKey) {
            'kanvas' => self::MISSING_IN_KANVAS,
            'netsuite' => self::MISSING_IN_NETSUITE,
            default => self::MISSING_IN_SOURCE,
        };
    }
}
