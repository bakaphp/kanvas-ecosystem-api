<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender\Traits;

/**
 * Reads an enum out of a receiver's configuration array, where the value is whatever an operator
 * last wrote — a valid case, a typo, a null, or a non-string. Anything unrecognised falls back
 * rather than throwing, so one bad config key cannot take the connector down.
 */
trait ParsesEnumValueTrait
{
    abstract protected static function fallback(): self;

    public static function tryFromValue(mixed $value): self
    {
        return (is_string($value) ? self::tryFrom($value) : null) ?? self::fallback();
    }
}
