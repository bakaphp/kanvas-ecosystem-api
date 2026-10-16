<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Enums;

enum OrderStatusEnum: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case DRAFT = 'draft';
    case CANCELED = 'canceled';
    case FAILED = 'failed';

    /**
     * Every status that means the order will never progress. Carries the legacy `cancelled` spelling
     * alongside the case: the model has only ever written `canceled`, but imported rows still hold
     * the other form, so a filter on the case alone shows a cancelled order as open.
     *
     * @return list<string>
     */
    public static function closedValues(): array
    {
        return [
            self::DRAFT->value,
            self::CANCELED->value,
            'cancelled',
            self::FAILED->value,
        ];
    }
}
