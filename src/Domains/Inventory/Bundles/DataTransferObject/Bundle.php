<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Bundles\DataTransferObject;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\Data;

class Bundle extends Data
{
    public function __construct(
        public Apps $app,
        public Companies $company,
        public Users $user,
        public string $name,
        public ?Variants $variant = null,
        public ?string $slug = null,
        public int $weight = 0,
        public ?string $description = null,
        public string $execution_mode = 'manual',
        public bool $expose_as_product = false,
        public array $variants = []
    ) {
    }
}
