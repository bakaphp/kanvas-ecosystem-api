<?php

declare(strict_types=1);

namespace Kanvas\Users\DataTransferObject;

use Spatie\LaravelData\Data;

class UpdateUser extends Data
{
    public function __construct(
        public string $firstname,
        public string $lastname,
        public bool $user_active,
        public bool $is_active,
        public bool $banned,
        public int $status,
        public bool $welcome,
        public string $configuration,
        public string $timezone,
    ) {
    }
}
