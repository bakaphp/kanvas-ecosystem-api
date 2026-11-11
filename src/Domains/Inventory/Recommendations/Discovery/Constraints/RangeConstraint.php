<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery\Constraints;

/**
 * `alsoAllow` admits one sentinel outside the range: 0 means "price unknown" on
 * a typed float field with no null, and an unpriced product must survive a floor.
 */
final readonly class RangeConstraint
{
    public function __construct(
        public string $field,
        public ?float $min = null,
        public ?float $max = null,
        public ?float $alsoAllow = null,
    ) {
    }
}
