<?php

declare(strict_types=1);

namespace Kanvas\Users\DataTransferObject;

use Spatie\LaravelData\Data;

class Invite extends Data
{
    /**
     * __construct.
     * @todo move to camel case
     **/
    public function __construct(
        public int $companies_branches_id,
        public int $role_id,
        public string $email,
        public ?string $firstname,
        public ?string $lastname,
        public ?string $description,
        public ?string $email_template = null,
    ) {
    }
}
