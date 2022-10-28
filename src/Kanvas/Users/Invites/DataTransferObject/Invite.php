<?php
declare(strict_types=1);
namespace Kanvas\Users\Invites\DataTransferObject;

class Invite
{
    /**
     * __construct
     * @param int $companies_branches_id
     * @param int $role_id
     * @param string $email
     * @param ?string $firstname
     * @param ?string $lastname
     * @param ?string $description
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

    /**
     * fromArray
     *
     * @param  array $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['companies_branches_id'],
            $data['role_id'],
            $data['email'],
            $data['firstname'] ?? null,
            $data['lastname'] ?? null,
            $data['description'] ?? null,
        );
    }
}
