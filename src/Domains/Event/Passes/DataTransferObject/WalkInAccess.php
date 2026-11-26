<?php

declare(strict_types=1);

namespace Kanvas\Event\Passes\DataTransferObject;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

final readonly class WalkInAccess
{
    public const string ATTRIBUTE_NAME = 'walk_in';
    public const int DEFAULT_MAX_USES = 2;
    public const int DEFAULT_DECISION_FROM_USE = 2;
    public const int DEFAULT_EXPIRE_UNUSED_MINUTES = 30;

    public function __construct(
        public int $maxUses = self::DEFAULT_MAX_USES,
        public int $decisionFromUse = self::DEFAULT_DECISION_FROM_USE,
        public int $expireUnusedMinutes = self::DEFAULT_EXPIRE_UNUSED_MINUTES,
    ) {
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
        $config = is_array($config) ? $config : [];

        return new self(
            maxUses: (int) ($config['max_uses'] ?? self::DEFAULT_MAX_USES),
            decisionFromUse: (int) ($config['decision_from_use'] ?? self::DEFAULT_DECISION_FROM_USE),
            expireUnusedMinutes: (int) ($config['expire_unused_minutes'] ?? self::DEFAULT_EXPIRE_UNUSED_MINUTES),
        );
    }
}
