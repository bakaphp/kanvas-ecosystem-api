<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery\Constraints;

final readonly class EqualsConstraint
{
    public function __construct(
        public string $field,
        public int|bool|string $value,
    ) {
    }
}
