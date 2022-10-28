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
    public static function getById(int $id) : Users
    {
        return Users::where('companies_id', auth()->user()->defaultCompany->id)
                ->where('id', $id)
                ->firstOrFail();
    }
}
