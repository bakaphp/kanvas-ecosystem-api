<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\DataTransferObject;

use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

final readonly class EventAccess
{
    public const string ATTRIBUTE_NAME = 'event_access';
    public const string DEFAULT_REFERENCE_PREFIX = 'MP';
    private const string REFERENCE_PREFIX_PATTERN = '/^[A-Z0-9]{2,6}$/';

    public function __construct(
        public int $eventId,
        public ?Carbon $redeemUntil = null,
        public string $referencePrefix = self::DEFAULT_REFERENCE_PREFIX,
    ) {
    }

    public function redeemWindowClosed(): bool
    {
        return $this->redeemUntil !== null && Carbon::now()->gt($this->redeemUntil);
    }

    public static function forResource(?Model $resource): ?self
    {
        $attribute = match (true) {
            $resource instanceof Variants => $resource->getAttributeByName(self::ATTRIBUTE_NAME)
                ?? $resource->product?->getAttributeByName(self::ATTRIBUTE_NAME),
            $resource instanceof Products => $resource->getAttributeByName(self::ATTRIBUTE_NAME),
            default => null,
        };

        if ($attribute === null) {
            return null;
        }

        $config = is_string($attribute->value) ? json_decode($attribute->value, true) : $attribute->value;

        if (! is_array($config)) {
            return new self(0);
        }

        $redeemUntil = self::parseDeadline($config['redeem_until'] ?? null);

        if ($redeemUntil === null && ! empty($config['redeem_until'])) {
            return new self(0);
        }

        $referencePrefix = strtoupper(trim((string) ($config['reference_prefix'] ?? self::DEFAULT_REFERENCE_PREFIX)));

        if (! preg_match(self::REFERENCE_PREFIX_PATTERN, $referencePrefix)) {
            return new self(0);
        }

        return new self(
            eventId: (int) ($config['event_id'] ?? 0),
            redeemUntil: $redeemUntil,
            referencePrefix: $referencePrefix
        );
    }

    private static function parseDeadline(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (InvalidFormatException) {
            return null;
        }
    }
}
