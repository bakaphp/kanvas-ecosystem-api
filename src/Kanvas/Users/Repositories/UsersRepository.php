<?php
declare(strict_types=1);
namespace Kanvas\Users\Repositories;

use Kanvas\Users\Models\Users;
use Illuminate\Database\Eloquent\Collection;

class UsersRepository
{
    /**
     * Get the user by email.
     *
     * @param int $email
     *
     * @return Users
     */
    public static function getById(int $id, int $companiesId) : Users
    {
        return Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
                ->where('users_associated_company.companies_id', $companiesId)
                ->where('id', $id)
                ->firstOrFail();
    }

    /**
     * getAll
     *
     * @param  int $companiesId
     * @return Users
     */
    public static function getAll(int $companiesId) : Collection
    {
        return Users::join('users_associated_company', 'users_associated_company.users_id', 'users.id')
                ->where('users_associated_company.companies_id', $companiesId)
                ->whereNot('users.id', auth()->user()->id)
                ->get();
    }
}
