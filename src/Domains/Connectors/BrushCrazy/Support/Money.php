<?php

declare(strict_types=1);

namespace Kanvas\Connectors\BrushCrazy\Support;

use JsonException;

/**
 * Decodes BrushCrazy's `App\Models\Casts\Money` columns, which are JSON of the shape
 * `{"amount": <minor units>, "currency": "USD"}` rather than a decimal.
 *
 * Casting the raw column to float yields 0.0 for every price, so every price, discount, deposit
 * and wage the importer reads has to come through here.
 */
final readonly class Money
{
    private function __construct(
        public int $cents,
        public string $currency,
    ) {
    }

    /**
     * Mirrors the source cast's `getAttribute()`, including its fallbacks: a blank or
     * unparseable value is zero, not an error, because the source stores both.
     */
    public static function fromRaw(mixed $value): self
    {
        if (is_array($value)) {
            return self::fromDecoded($value);
        }

        if (! is_string($value) || trim($value) === '') {
            return new self(0, 'USD');
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self(0, 'USD');
        }

        if (! is_array($decoded)) {
            return new self(0, 'USD');
        }

        return self::fromDecoded($decoded);
    }

    /**
     * `sale_items.discount` is not a plain Money — it wraps one:
     * `{"name":null,"type":"fixed","amount":{"amount":"0","currency":"USD"},"reason":null}`.
     * Recursing on an array `amount` reads both shapes; casting it directly would yield 1 cent,
     * because `(int) []` is 1.
     */
    private static function fromDecoded(array $decoded): self
    {
        $amount = $decoded['amount'] ?? 0;

        if (is_array($amount)) {
            return self::fromDecoded($amount);
        }

        return new self((int) $amount, (string) ($decoded['currency'] ?? 'USD'));
    }

    /** Kanvas stores ticket prices as float dollars (`event_versions.price_per_ticket`). */
    public function dollars(): float
    {
        return $this->cents / 100;
    }

    /** Stable component for a dedupe slug — cents avoid the float formatting drift dollars have. */
    public function slugComponent(): string
    {
        return (string) $this->cents;
    }

    public function isZero(): bool
    {
        return $this->cents === 0;
    }
}
