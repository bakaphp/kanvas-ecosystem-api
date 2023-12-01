<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\UserLinkedSources;

class UsersLinkedSourcesRepository
{
    /**
     * Get record by users_id
     *
     * @param  int $usersId
     *
     * @return UserLinkedSources
     */
    public static function getByUsersId(int $usersId): UserLinkedSources
    {
        return UserLinkedSources::where('users_id', $usersId)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }
}
