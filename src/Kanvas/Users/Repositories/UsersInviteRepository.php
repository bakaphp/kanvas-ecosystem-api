<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Users\Models\UsersInvite as UsersInviteModel;

class UsersInviteRepository
{
    /**
     * getById.
     *
     */
    public static function getById(int $id, Companies $company, ?AppInterface $app = null): UsersInviteModel
    {
        $app = $app ?: app(Apps::class);
        return UsersInviteModel::fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Get invite by its hash.
     *
     */
    public static function getByHash(string $hash, ?AppInterface $app = null): UsersInviteModel
    {
        $app = $app ?: app(Apps::class);
        return UsersInviteModel::where('invite_hash', $hash)
            ->fromApp($app)
            ->notDeleted()
            ->firstOrFail();
    }
}
