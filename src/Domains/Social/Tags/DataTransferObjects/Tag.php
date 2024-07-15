<?php

declare(strict_types=1);

namespace Kanvas\Social\Tags\DataTransferObjects;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Tag extends Data
{
    public function __construct(
        public Apps $app,
        public Users $user,
        public Companies $company,
        public string $name,
        public ?string $slug = null,
        public int $weight = 0
    ) {
    }
}
