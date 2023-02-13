<?php

declare(strict_types=1);

namespace Kanvas\Users\DataTransferObject;

use Spatie\LaravelData\Data;

class Invite extends Data
{
    /**
     * __construct.
     *
     * @param int $companies_branches_id
     * @param int $role_id
     * @param string $email
     * @param ?string $firstname
     * @param ?string $lastname
     * @param ?string $description
     *
     * @return void
     */
    public function __construct(
        public int $companies_branches_id,
        public int $role_id,
        public string $email,
        public ?string $firstname,
        public ?string $lastname,
        public ?string $description,
    ) {
    }
}
