<?php

declare(strict_types=1);

namespace Kanvas\Connectors\ChromeData\DataTransferObject;

use Spatie\LaravelData\Data;

class ColorData extends Data
{
    public function __construct(
        public readonly ?string $code,
        public readonly ?string $name,
        public readonly ?string $rgb,
    ) {
    }
}
