<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Rules;

/**
 * A text longer than ten segments is rejected by carriers after the provider has already taken it
 * (Twilio's 30019), so it is refused here, before it becomes a billed failure.
 */
final class BodyLength
{
    public const int CARRIER_SAFE_LIMIT = 1600;

    /**
     * @param int|null $configuredLimit a tenant's tighter limit; one above the carrier's is ignored
     */
    public static function limit(?int $configuredLimit = null): int
    {
        return max(1, min(self::CARRIER_SAFE_LIMIT, $configuredLimit ?: self::CARRIER_SAFE_LIMIT));
    }

    public static function exceeds(string $body, ?int $configuredLimit = null): bool
    {
        return mb_strlen($body, 'UTF-8') > self::limit($configuredLimit);
    }
}
