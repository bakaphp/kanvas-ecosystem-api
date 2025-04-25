<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Dealers;

class User
{
    public int $id;
    public ?string $group = null;
    public array $types;
    public string $email;
    public string $fullName;
    public string $firstName;
    public string $lastName;
    public string $role;

    /**
     * Initialize.
     */
    public function __construct(array $data)
    {
        $this->id = $data['UserId'];
        $this->group = $data['UserGroup'];
        $this->types = $data['UserTypes'];
        $this->email = $data['EmailAddress'];
        $this->fullName = $data['FullName'];
        $this->firstName = $data['FirstName'];
        $this->lastName = $data['LastName'];
        $this->role = $data['IlmAccess'];
    }
}
