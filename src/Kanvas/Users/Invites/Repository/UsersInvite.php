<?php
declare(strict_types=1);
namespace Kanvas\Users\Invites\Repository;

use Kanvas\Users\Invites\Models\UsersInvite as UsersInviteModel;
use Kanvas\Apps\Models\Apps;

class UsersInvite
{
    /**
     * getById
     *
     * @param  int $id
     * @return UsersInvite
     */
    public static function getById(int $id): UsersInviteModel
    {
        $invite = UsersInviteModel::where('apps_id', app(Apps::class)->id)
            ->where('companies_id', auth()->user()->defaultCompany->id)
            ->where('id', $id)
            ->firstOrFail();
        return $invite;
    }
}
