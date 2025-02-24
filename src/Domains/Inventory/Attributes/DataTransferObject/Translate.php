<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\DataTransferObject;

use Spatie\LaravelData\Data;

class Translate extends Data
{
    /**
     * __construct.
     *
     * @return void
     */
    public function __construct(
        public ?string $name = null
    ) {
    }
}
