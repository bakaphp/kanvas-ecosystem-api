<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PaymentRuns\Enums;

enum PaymentRunStatusEnum: string
{
    case DRAFT = 'draft';
    case SENT = 'sent';
    case PARTIALLY_PAID = 'partially_paid';
    case PAID = 'paid';

    public function isOpen(): bool
    {
        return $this !== self::PAID;
    }

    /**
     * @return list<string>
     */
    public static function openValues(): array
    {
        return [self::DRAFT->value, self::SENT->value, self::PARTIALLY_PAID->value];
    }
}
