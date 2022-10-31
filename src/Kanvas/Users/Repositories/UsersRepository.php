<?php
declare(strict_types=1);
namespace Kanvas\Users\Repositories;

use Kanvas\Users\Models\Users;

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
        return Users::where('companies_id', $companiesId)
                ->where('id', $id)
                ->firstOrFail();
    }
}
