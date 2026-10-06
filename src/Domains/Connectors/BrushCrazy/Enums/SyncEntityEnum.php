<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Enums;

/**
 * Sync cursors are per (company, entity). A single global cursor advanced after a partial failure
 * silently drops rows forever; per-entity cursors let attendees retry while calendarables stay
 * current.
 */
enum SyncEntityEnum: string
{
    case STUDIOS = 'studios';
    case CUSTOMERS = 'customers';
    case STAFF = 'staff';
    case PAINTINGS = 'paintings';
    case SUBSTRATE_CATALOG = 'substrate-catalog';
    case CALENDARABLES = 'calendarables';
    case EVENT_SUBSTRATES = 'event-substrates';
    case REGISTRATIONS = 'registrations';
    case ORDERS = 'orders';
    case MEDIA = 'media';

    public function cursorKey(): string
    {
        return 'BRUSHCRAZY_SYNC_CURSOR_' . str_replace('-', '_', strtoupper($this->value));
    }

    /**
     * Entities the mirror re-reads on every run. The rest are catalog-shaped: they change rarely
     * and are pulled in full rather than windowed.
     */
    public function isIncremental(): bool
    {
        return match ($this) {
            self::CALENDARABLES, self::REGISTRATIONS, self::CUSTOMERS, self::ORDERS => true,
            default => false,
        };
    }
}
