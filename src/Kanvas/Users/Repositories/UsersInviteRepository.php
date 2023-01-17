<?php
declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Enums\StateEnums;
use Kanvas\Users\Models\UsersInvite as UsersInviteModel;

class UsersInviteRepository
{
    /**
     * getById.
     *
     * @param  int $id
     *
     * @return UsersInvite
     */
    public static function getById(int $id, Companies $company) : UsersInviteModel
    {
        return UsersInviteModel::where('apps_id', app(Apps::class)->id)
            ->where('companies_id', $company->getKey())
            ->where('is_deleted', StateEnums::NO->getValue())
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Get invite by its hash.
     *
     * @param  string $hash
     *
     * @return UsersInviteModel
     */
    public static function getByHash(string $hash) : UsersInviteModel
    {
        return UsersInviteModel::where('invite_hash', $hash)
            ->where('apps_id', app(Apps::class)->id)
            ->where('is_deleted', StateEnums::NO->getValue())
            ->firstOrFail();
    }
}
