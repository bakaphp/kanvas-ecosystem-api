<?php

declare(strict_types=1);

namespace Kanvas\AccessControlList\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Spatie\LaravelData\Data;

class RoleType extends Data
{
    public function __construct(
        public Apps $app,
        public string $name,
        public ?string $description = null,
    ) {
    }
}
