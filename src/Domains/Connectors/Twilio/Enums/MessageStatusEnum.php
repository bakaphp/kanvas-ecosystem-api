<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Enums;

/**
 * Twilio's Message `status` values, as the Messages API and the status callback spell them.
 */
enum MessageStatusEnum: string
{
    case ACCEPTED = 'accepted';
    case SCHEDULED = 'scheduled';
    case QUEUED = 'queued';
    case SENDING = 'sending';
    case SENT = 'sent';
    case RECEIVING = 'receiving';
    case RECEIVED = 'received';
    case DELIVERED = 'delivered';
    case UNDELIVERED = 'undelivered';
    case FAILED = 'failed';
    case READ = 'read';
    case PARTIALLY_DELIVERED = 'partially_delivered';
    case CANCELED = 'canceled';

    public static function fromTwilio(?string $status): ?self
    {
        return self::tryFrom(strtolower(trim((string) $status)));
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::DELIVERED, self::UNDELIVERED, self::FAILED, self::CANCELED], true);
    }

    /** Whether the text reached the handset or definitively did not — what a client is told live. */
    public function isDeliveryOutcome(): bool
    {
        return in_array($this, [self::DELIVERED, self::UNDELIVERED, self::FAILED], true);
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::UNDELIVERED, self::FAILED], true);
    }
}
