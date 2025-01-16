<?php

namespace Kanvas\Social\UsersRatings\Traits;

use Kanvas\Apps\Models\Apps;
use Kanvas\Social\UsersRatings\Models\UsersRatings as UsersRatingsModel;
use Kanvas\SystemModules\Repositories\SystemModulesRepository;

trait UsersRatings
{
    public function usersRatings()
    {
        $systemModule = SystemModulesRepository::getByModelName(self::class, app(Apps::class));

        return $this->hasMany(UsersRatingsModel::class, 'entity_id')->where('system_modules_id', $systemModule->getId());
    }
}
