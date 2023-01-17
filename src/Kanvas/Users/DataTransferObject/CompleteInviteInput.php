<?php
declare(strict_types=1);

namespace Kanvas\Users\DataTransferObject;

use Spatie\LaravelData\Data;

class CompleteInviteInput extends Data
{
    /**
     * Construct.
     *
     * @param string $invite_hash
     * @param string $password
     * @param string $firstname
     * @param string $lastname
     */
    public function __construct(
        public string $invite_hash,
        public string $password,
        public string $firstname,
        public string $lastname
    ) {
    }

    /**
     * Get invite hash
     *
     * @return string
     */
    public function getInviteHash() : string
    {
        return $this->invite_hash;
    }
}
