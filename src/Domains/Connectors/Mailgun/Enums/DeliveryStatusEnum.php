<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Enums;

use Kanvas\Social\Messages\Contracts\DeliveryStatus;
use Override;

/**
 * What an outbound email's Mailgun event says about its delivery. Mailgun reports both kinds of
 * failure as `failed` and tells them apart by `severity`: a temporary one is retried by Mailgun
 * itself, a permanent one is final.
 *
 * `complained` and `unsubscribed` arrive after delivery and say nothing new about it, so they never
 * move the attempt's status; they only drive the remediation.
 */
enum DeliveryStatusEnum: string implements DeliveryStatus
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

    public static function tryFromStatus(?string $status): ?self
    {
        return self::tryFrom(strtolower(trim((string) $status)));
    }

    #[Override]
    public function provider(): string
    {
        return 'mailgun';
    }

    #[Override]
    public function isDeliveryOutcome(): bool
    {
        return in_array($this, [self::DELIVERED, self::FAILED], true);
    }

    public function changesDeliveryStatus(): bool
    {
        return ! in_array($this, [self::COMPLAINED, self::UNSUBSCRIBED], true);
    }

    /**
     * An attempt only moves forward: a late `accepted` or a temporary failure Mailgun already
     * recovered from must not overwrite `delivered`, and nothing overwrites a final outcome.
     */
    public function canFollow(?self $current): bool
    {
        if ($current === null) {
            return true;
        }

        if ($current->isDeliveryOutcome()) {
            return false;
        }

        return $this->rank() >= $current->rank();
    }

    public function classification(): ?string
    {
        return match ($this) {
            self::TEMPORARY_FAILURE => 'temporary_failure',
            self::FAILED => 'hard_bounce',
            self::COMPLAINED => 'complained',
            self::UNSUBSCRIBED => 'unsubscribed',
            default => null,
        };
    }

    public function remediationAction(): ?string
    {
        return match ($this) {
            self::FAILED, self::COMPLAINED, self::UNSUBSCRIBED => 'suppress_email',
            default => null,
        };
    }

    private function rank(): int
    {
        return match ($this) {
            self::ACCEPTED => 10,
            self::TEMPORARY_FAILURE => 20,
            default => 100,
        };
    }
}
