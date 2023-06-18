<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Spatie\LaravelData\Data;

class Contact extends Data
{
    /**
     * __construct.
     */
    public function __construct(
        public readonly string $value,
        public readonly int $type_id,
        public readonly int $weight = 0,
    ) {
    }
}
