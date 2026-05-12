<?php

declare(strict_types=1);

namespace Kanvas\Guild\Organizations\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class OrganizationType extends Data
{
    public function __construct(
        public readonly Apps $apps,
        public readonly Companies $companies,
        public readonly UserInterface $user,
        public readonly string $name,
        public readonly ?string $description = null,
        public readonly bool $is_active = true,
        public readonly bool $is_default = false,
        public readonly ?array $config = null,
    ) {
    }
}
