<?php

declare(strict_types=1);

namespace Kanvas\Users\Repositories;

use Baka\Contracts\AppInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Users\Models\AdminInvite as AdminInviteModel;

class AdminInviteRepository
{
    /**
     * getById.
     *
     * @param  int $id
     *
     * @return AdminInviteModel
     */
    public static function getById(int $id, ?AppInterface $app = null): AdminInviteModel
    {
        $app = $app ?: app(Apps::class);
        return AdminInviteModel::fromApp($app)
            ->notDeleted()
            ->where('id', $id)
            ->firstOrFail();
    }

    /**
     * Get invite by its hash.
     *
     * @param  string $hash
     *
     * @return AdminInviteModel
     */
    public static function getByHash(string $hash, ?AppInterface $app = null): AdminInviteModel
    {
        return AdminInviteModel::where('invite_hash', $hash)
            ->notDeleted()
            ->firstOrFail();
    }
}
