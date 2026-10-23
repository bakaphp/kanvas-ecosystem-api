<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Discovery\Constraints;

final readonly class InConstraint
{
    /**
     * @param list<string> $values
     */
    public function __construct(
        public string $field,
        public array $values,
    ) {
    }
}
