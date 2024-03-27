<?php

declare(strict_types=1);

namespace Kanvas\Users\DataTransferObject;

use Spatie\LaravelData\Data;

class CompleteInviteInput extends Data
{
    /**
     * Construct.
     */
    public function __construct(
        public string $invite_hash,
        public string $password,
        public string $firstname,
        public string $lastname,
        public string $phone_number
    ) {
    }

    /**
     * Get invite hash.
     */
    public function getInviteHash(): string
    {
        return $this->invite_hash;
    }
}
