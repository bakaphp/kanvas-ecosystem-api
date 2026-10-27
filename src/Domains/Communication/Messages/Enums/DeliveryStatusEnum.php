<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Enums;

enum DeliveryStatusEnum: string
{
    case PENDING = 'pending';
    case SENDING = 'sending';
    case ACCEPTED = 'accepted';
    case DELIVERED = 'delivered';
    case READ = 'read';
    case FAILED = 'failed';

    /**
     * The vendor call was made and its outcome was lost. Never retried automatically — the message
     * may have gone out — only looked up.
     */
    case UNKNOWN = 'unknown';
    case SKIPPED = 'skipped';

    /**
     * Providers retry and reorder their callbacks, so an event may only move a delivery forward: a
     * late "sent" after "delivered" is recorded and changes nothing.
     */
    public function canAdvanceTo(self $next): bool
    {
        return match ($this) {
            self::SENDING, self::UNKNOWN => in_array($next, [self::ACCEPTED, self::DELIVERED, self::READ, self::FAILED], true),
            self::ACCEPTED => in_array($next, [self::DELIVERED, self::READ, self::FAILED], true),
            self::DELIVERED => $next === self::READ,
            default => false,
        };
    }

    public function reachedTheProvider(): bool
    {
        return in_array($this, [self::ACCEPTED, self::DELIVERED, self::READ], true);
    }
}
