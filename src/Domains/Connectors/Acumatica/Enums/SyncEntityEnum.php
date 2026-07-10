<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Enums;

/**
 * The entities the scheduled sync pulls, in dependency order. `isIncremental()` marks the ones
 * driven off a LastModifiedDateTime high-watermark (the high-volume tables); the rest are small
 * master data pulled in full every run (cheap + idempotent).
 */
enum SyncEntityEnum: string
{
    case WAREHOUSES = 'warehouses';
    case ACCOUNTS = 'accounts';
    case PERIODS = 'periods';
    case PRODUCTS = 'products';
    case STOCK = 'stock';
    case CUSTOMERS = 'customers';
    case VENDORS = 'vendors';
    case ORDERS = 'orders';
    case JOURNAL_ENTRIES = 'journal_entries';
    case INVOICES = 'invoices';
    case BILLS = 'bills';

    public function isIncremental(): bool
    {
        return match ($this) {
            self::WAREHOUSES, self::ACCOUNTS, self::PERIODS => false,
            default => true,
        };
    }

    public function cursorKey(): string
    {
        return ConfigurationEnum::SYNC_CURSOR_PREFIX->value . $this->value;
    }
}
