<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\DataTransferObject;

use Spatie\LaravelData\Data;

class UserList extends Data
{
    public function __construct(
        public int $apps_id,
        public int $companies_id,
        public int $users_id,
        public string $name = '',
        public string $description = '',
        public bool $is_public = false,
        public bool $is_default = false,
        public array $files = [],
    ) {
    }
}
