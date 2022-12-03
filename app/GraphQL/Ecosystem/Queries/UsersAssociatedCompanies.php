<?php
declare(strict_types=1);
namespace App\GraphQL\Ecosystem\Queries;

use Kanvas\Users\Repositories\UsersRepository;

class UsersAssociatedCompanies
{
    public function __invoke()
    {
        return UsersRepository::getAll(auth()->user()->defaultCompany->id);
    }
}
