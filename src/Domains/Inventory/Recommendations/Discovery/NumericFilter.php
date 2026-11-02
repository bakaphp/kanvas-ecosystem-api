<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery;

/**
 * A validated bound on a configured numeric attribute, already in that field's
 * canonical unit.
 */
final readonly class NumericFilter
{
    public function __construct(
        public string $field,
        public ?float $min = null,
        public ?float $max = null,
    ) {
    }

    public function admits(?float $value): bool
    {
        if ($value === null) {
            return false;
        }

        return ($this->min === null || $value >= $this->min)
            && ($this->max === null || $value <= $this->max);
    }
}
