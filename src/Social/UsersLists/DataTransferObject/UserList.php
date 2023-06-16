<?php

declare(strict_types=1);

namespace Kanvas\Social\UsersLists\DataTransferObject;

use Spatie\LaravelData\Data;

class UserList extends Data
{
    public function __construct(
        public int $apps_id = 0,
        public int $companies_id = 0,
        public int $users_id = 0,
        public string $name = '',
        public string $description = '',
        public bool $is_public = false,
        public bool $is_default = false,
    ) {
    }
}
