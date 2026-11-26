<?php

declare(strict_types=1);

namespace Kanvas\Scribe\SalesOrders\Enums;

/**
 * Which check of a sales order a difference came from, and so where it is stored.
 */
enum DifferenceSourceEnum: string
{
    case REVIEW = 'review';
    case DOCUMENTS = 'documents';

    public function column(): string
    {
        return match ($this) {
            self::REVIEW => 'review',
            self::DOCUMENTS => 'documents_check',
        };
    }
}
