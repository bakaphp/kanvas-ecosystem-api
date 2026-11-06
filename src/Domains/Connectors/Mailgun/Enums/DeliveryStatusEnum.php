<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum as CommunicationDeliveryStatusEnum;

/**
 * A Mailgun event about an outbound email's delivery. Mailgun reports both kinds of failure as `failed`
 * and tells them apart by `severity`: a temporary one is retried by Mailgun itself, a permanent one is
 * final. The value is what the delivery event stores as the provider's own status.
 */
enum DeliveryStatusEnum: string
{
    case ACCEPTED = 'accepted';
    case TEMPORARY_FAILURE = 'temporary_failure';
    case DELIVERED = 'delivered';
    case FAILED = 'failed';
    case COMPLAINED = 'complained';
    case UNSUBSCRIBED = 'unsubscribed';

    public static function fromEvent(string $event, string $severity): ?self
    {
        return match (strtolower(trim($event))) {
            'accepted' => self::ACCEPTED,
            'delivered' => self::DELIVERED,
            'failed' => strtolower(trim($severity)) === 'temporary' ? self::TEMPORARY_FAILURE : self::FAILED,
            'complained' => self::COMPLAINED,
            'unsubscribed' => self::UNSUBSCRIBED,
            default => null,
        };
    }

    /**
     * A temporary failure is Mailgun still trying, so it stays `accepted`. A complaint or an unsubscribe
     * can only come from someone who received the email, so it reads as `delivered`; the delivery only
     * moves forward, so neither undoes a later outcome.
     */
    public function toDeliveryStatus(): CommunicationDeliveryStatusEnum
    {
        return match ($this) {
            self::ACCEPTED, self::TEMPORARY_FAILURE => CommunicationDeliveryStatusEnum::ACCEPTED,
            self::DELIVERED, self::COMPLAINED, self::UNSUBSCRIBED => CommunicationDeliveryStatusEnum::DELIVERED,
            self::FAILED => CommunicationDeliveryStatusEnum::FAILED,
        };
    }

    public function isFailure(): bool
    {
        return in_array($this, [self::TEMPORARY_FAILURE, self::FAILED], true);
    }

    /**
     * A spam complaint or an unsubscribe is the person asking us to stop.
     */
    public function isStopRequest(): bool
    {
        return in_array($this, [self::COMPLAINED, self::UNSUBSCRIBED], true);
    }
}
