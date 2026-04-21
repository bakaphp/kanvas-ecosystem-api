<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Spatie\LaravelData\Data;

class PeopleType extends Data
{
    public function __construct(
        public Apps $apps,
        public Companies $companies,
        public UserInterface $user,
        public string $name,
        public ?string $description = null,
        public bool $is_active = true,
        public bool $is_default = false,
        public ?array $config = null,
    ) {
    }
}
