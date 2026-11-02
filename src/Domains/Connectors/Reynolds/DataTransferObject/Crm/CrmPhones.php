<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Reynolds\DataTransferObject\Crm;

use Spatie\LaravelData\Data;

class CrmPhones extends Data
{
    public function __construct(
        public readonly ?string $cell = null,
        public readonly ?string $home = null,
        public readonly ?string $work = null
    ) {
    }
}
